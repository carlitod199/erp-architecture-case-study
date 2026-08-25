<?php

declare(strict_types=1);

namespace Reference;

/**
 * Permissions — role/permission checks with wildcards.
 *
 * THE PROBLEM
 * An ERP with a few dozen screens produces hundreds of permission slugs, three
 * per screen once you separate view / edit / delete. Nobody administers that
 * list one checkbox at a time, and a role defined as an explicit list of four
 * hundred slugs silently stops covering new screens the day they ship — the new
 * screen is invisible to every existing role until someone remembers to grant it.
 *
 * THE DECISION
 * A hierarchical slug — `module.screen.action` — plus four wildcard forms:
 *
 *   *                 everything (the system administrator)
 *   module.*          every action on every screen of a module
 *   module.screen.*   every action on one screen
 *   *.view            one action across every module (the read-only auditor)
 *
 * The grants are resolved once at login into a flat list on the session, and
 * every check is then an in-memory string comparison. No database round trip per
 * check, which matters because a single page renders dozens of them while
 * deciding which buttons to draw.
 *
 * A rule worth stating explicitly because it surprises people: `module.view` does
 * NOT imply `module.screen.view`. Seeing that a module exists in the navigation
 * is a different grant from seeing the data on one of its screens. Making the
 * parent imply the children would mean every "let them see the menu" grant
 * quietly hands over every screen underneath it.
 *
 * TRADE-OFF ACCEPTED
 * Wildcards are convenient and imprecise. `module.*` grants permissions that do
 * not exist yet, which is the entire point — and also the risk, since a screen
 * added next quarter is live for everyone holding the wildcard without anybody
 * deciding that. The mitigation is that genuinely sensitive actions get slugs
 * OUTSIDE the wildcard namespace (an approval, a reopen, a write-off), so they
 * must be granted explicitly and appear in an audit of who holds them.
 *
 * The second trade-off: permissions are snapshotted into the session at login,
 * so revoking a permission does not take effect until that user's session ends.
 * Checking the database on every request would fix it and cost a query per
 * check; a middle path is to version the role and re-resolve when the version
 * moves. The version in production here is the snapshot, and the honest
 * description of it is "revocation takes effect at next login".
 */
final class Permissions
{
    /** @var list<string> */
    private array $granted;

    /**
     * @param list<string> $granted        Flat permission slugs, wildcards allowed.
     * @param list<string> $superuserRoles Roles that bypass the list entirely.
     */
    public function __construct(
        array $granted,
        private string $role = '',
        private array $superuserRoles = ['system_admin']
    ) {
        $this->granted = array_values(array_unique(array_map('strval', $granted)));
    }

    public function allows(string $permission): bool
    {
        if ($permission === '') {
            return false;
        }

        if ($this->role !== '' && in_array($this->role, $this->superuserRoles, true)) {
            return true;
        }

        if (in_array('*', $this->granted, true) || in_array($permission, $this->granted, true)) {
            return true;
        }

        // Namespace wildcards: walk the prefixes of the requested slug and ask
        // whether any of them was granted with `.*`. Walking the REQUEST rather
        // than scanning the grants keeps this O(depth) instead of O(grants), and
        // depth is three.
        $segments = explode('.', $permission);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (in_array($prefix . '.*', $this->granted, true)) {
                return true;
            }
        }

        // Action wildcard: `*.view` matches any slug whose last segment is view.
        if (count($segments) > 1 && in_array('*.' . end($segments), $this->granted, true)) {
            return true;
        }

        return false;
    }

    public function allowsAny(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($permission)) {
                return true;
            }
        }

        return false;
    }

    public function allowsAll(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guard for a write path. Throwing rather than returning false makes the
     * failure impossible to ignore: a forgotten `if` around a check is a silent
     * authorisation hole, a forgotten call to this is at least a missing line in
     * a code review.
     */
    public function require(string $permission): void
    {
        if (!$this->allows($permission)) {
            throw new PermissionDenied($permission, $this->role);
        }
    }

    /** @return list<string> */
    public function granted(): array
    {
        return $this->granted;
    }

    public function role(): string
    {
        return $this->role;
    }
}

final class PermissionDenied extends \RuntimeException
{
    public function __construct(public readonly string $permission, public readonly string $role = '')
    {
        parent::__construct('Permission denied: ' . $permission);
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $manager  = new Permissions(['inventory.*', 'finance.invoices.view'], 'manager');
    $auditor  = new Permissions(['*.view'], 'auditor');
    $admin    = new Permissions([], 'system_admin');

    $checks = [
        'inventory.receipts.edit',
        'inventory.view',
        'finance.invoices.view',
        'finance.invoices.edit',
        'finance.invoices.approve',
    ];

    printf("%-28s %-9s %-9s %s%s", 'permission', 'manager', 'auditor', 'admin', PHP_EOL);
    foreach ($checks as $check) {
        printf(
            "%-28s %-9s %-9s %s%s",
            $check,
            $manager->allows($check) ? 'allow' : 'deny',
            $auditor->allows($check) ? 'allow' : 'deny',
            $admin->allows($check) ? 'allow' : 'deny',
            PHP_EOL
        );
    }
}
