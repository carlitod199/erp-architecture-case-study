<?php

declare(strict_types=1);

namespace Reference;

/**
 * SessionGuard — idle timeout, absolute timeout, and safe periodic id rotation.
 *
 * THE PROBLEM
 * Three requirements pull against each other:
 *   - A stolen session id must stop being useful. That argues for rotating the
 *     id often and for expiring idle sessions quickly.
 *   - An operator filling in a long form must not be logged out mid-task. That
 *     argues for long idle windows.
 *   - The same user routinely has four or five tabs open on the same session.
 *
 * The naive implementation, `session_regenerate_id(true)`, satisfies the first
 * and destroys the third. It deletes the old session record immediately, so any
 * request already in flight from another tab — still carrying the old cookie —
 * arrives at a session id that no longer exists. Unless strict mode is on, PHP
 * happily creates a fresh EMPTY session under that id, the application mints a
 * new CSRF token into it, and the user is silently logged out or has every open
 * form invalidated. The symptom looks random and is hard to reproduce, because
 * it only happens when a rotation lands between two tabs' requests.
 *
 * THE DECISION
 * Rotate on a schedule, but do not destroy the old session. Stamp it with
 * `_rotated_at`, commit it, and start the new id with the same payload. For a
 * short grace window the old id still resolves to a readable session, so
 * in-flight requests from other tabs complete normally and pick up the new
 * cookie on their next response. After the grace window the old id is treated as
 * expired. This is the pattern the PHP manual documents around session_create_id.
 *
 * THE OTHER TWO CLOCKS
 * `last_activity` slides forward on every request (idle timeout), `login_at`
 * never moves (absolute timeout). Both are enforced in application code rather
 * than left to the session garbage collector — but `session.gc_maxlifetime` must
 * still be raised to at least the absolute timeout, otherwise the collector
 * deletes the session file out from under a policy that thinks it is still
 * valid, and sessions die at an interval nobody configured.
 *
 * TRADE-OFF ACCEPTED
 * For up to `rotationGrace` seconds, two session ids are valid for one user. An
 * attacker who captured the pre-rotation id keeps it for that window instead of
 * losing it instantly. That window is measured in seconds, is bounded, and is
 * the price of not logging users out of their own tabs — a failure that is both
 * certain and constant, versus a risk that is narrow and timed.
 */
final class SessionGuard
{
    public const OK      = 'ok';
    public const EXPIRE  = 'expire';
    public const ROTATE  = 'rotate';

    public function __construct(
        private int $idleTimeout = 3600,       // 60 min without a request
        private int $absoluteTimeout = 28800,  // 8 h since login, no matter what
        private int $rotateEvery = 600,        // mint a new id every 10 min
        private int $rotationGrace = 60,       // how long the previous id stays readable
        private string $cookieName = 'app_session'
    ) {
    }

    /**
     * Cookie attributes. Each one closes a specific door:
     *   httponly  — script cannot read the cookie, so an XSS bug cannot exfiltrate it
     *   secure    — the cookie never travels over plaintext HTTP
     *   samesite  — Lax stops the cookie riding along on cross-site POSTs (CSRF),
     *               while still surviving ordinary top-level navigation into the
     *               app from an email or a bookmark. Strict would break those.
     *   lifetime 0— session cookie: closing the browser drops it
     *   __Host-   — prefix browsers only accept on a secure, host-scoped,
     *               path-/ cookie, so a sibling subdomain cannot overwrite it
     */
    public function cookieParams(bool $https): array
    {
        return [
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function cookieName(bool $https): string
    {
        return $https ? '__Host-' . $this->cookieName : $this->cookieName;
    }

    /** True when the request reached us over TLS, directly or through a proxy. */
    public static function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }

        return (int) ($server['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * Start a session with the right cookie flags and a garbage-collection
     * lifetime that cannot undercut the absolute timeout.
     */
    public function start(array $server): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $https = self::isHttps($server);

        ini_set('session.use_strict_mode', '1');   // refuse ids the server never issued
        ini_set('session.use_only_cookies', '1');  // never accept an id from the query string
        ini_set('session.gc_maxlifetime', (string) $this->absoluteTimeout);

        session_name($this->cookieName($https));
        session_set_cookie_params($this->cookieParams($https));
        session_start();
    }

    /**
     * The whole policy as a pure function, so it can be unit-tested without a
     * live session. `$state` is the session payload; `$now` is a unix timestamp.
     *
     * @return array{0:string,1:string} [verdict, reason]
     */
    public function evaluate(array $state, int $now): array
    {
        $lastActivity = (int) ($state['last_activity'] ?? 0);
        if ($lastActivity > 0 && ($now - $lastActivity) > $this->idleTimeout) {
            return [self::EXPIRE, 'idle_timeout'];
        }

        $loginAt = (int) ($state['login_at'] ?? $now);
        if (($now - $loginAt) > $this->absoluteTimeout) {
            return [self::EXPIRE, 'absolute_timeout'];
        }

        // We arrived on an id that has already been rotated away from. Inside the
        // grace window that is an in-flight request from another tab: let it work
        // and do NOT rotate again. Outside it, the id is stale.
        $rotatedAt = (int) ($state['_rotated_at'] ?? 0);
        if ($rotatedAt > 0) {
            return ($now - $rotatedAt) > $this->rotationGrace
                ? [self::EXPIRE, 'rotated_away']
                : [self::OK, 'rotation_grace'];
        }

        $lastRotation = (int) ($state['_rotated_from'] ?? 0);
        if (($now - $lastRotation) > $this->rotateEvery) {
            return [self::ROTATE, 'rotation_due'];
        }

        return [self::OK, 'active'];
    }

    /**
     * Apply the verdict to the live session. Returns the verdict so the caller
     * decides what an expiry means for this request (a redirect for a browser,
     * a 401 envelope for an API client).
     */
    public function guard(?int $now = null): array
    {
        $now ??= time();
        [$verdict, $reason] = $this->evaluate($_SESSION ?? [], $now);

        if ($verdict === self::EXPIRE) {
            $this->destroy();

            return [$verdict, $reason];
        }

        if ($verdict === self::ROTATE) {
            $this->rotate($now);
        }

        $_SESSION['last_activity'] = $now;

        return [$verdict, $reason];
    }

    /**
     * Mint a new id, carry the payload across, and leave the old record readable
     * for the grace window instead of deleting it.
     */
    public function rotate(int $now): void
    {
        $payload = $_SESSION;

        $newId = session_create_id();
        $_SESSION['_rotated_at'] = $now;   // stamp lands ONLY on the outgoing record
        session_commit();                  // write and close the old session

        session_id($newId);
        // The id we are about to open was created by session_create_id() on this
        // server a microsecond ago, but strict mode has no way to know that, so it
        // must be relaxed for exactly this call and restored immediately after.
        $strict = ini_get('session.use_strict_mode');
        ini_set('session.use_strict_mode', '0');
        session_start();
        ini_set('session.use_strict_mode', (string) $strict);

        unset($payload['_rotated_at']);
        $_SESSION = $payload;
        $_SESSION['_rotated_from'] = $now;
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $guard = new SessionGuard(idleTimeout: 60, absoluteTimeout: 600, rotateEvery: 30, rotationGrace: 10);
    $now = 1_000_000;

    $cases = [
        'fresh session'                 => ['login_at' => $now, 'last_activity' => $now, '_rotated_from' => $now],
        'idle too long'                 => ['login_at' => $now, 'last_activity' => $now - 61],
        'past the absolute limit'       => ['login_at' => $now - 601, 'last_activity' => $now],
        'rotation due'                  => ['login_at' => $now, 'last_activity' => $now, '_rotated_from' => $now - 31],
        'other tab, inside grace'       => ['login_at' => $now, 'last_activity' => $now, '_rotated_at' => $now - 5],
        'other tab, grace expired'      => ['login_at' => $now, 'last_activity' => $now, '_rotated_at' => $now - 11],
    ];

    foreach ($cases as $label => $state) {
        [$verdict, $reason] = $guard->evaluate($state, $now);
        printf("%-26s %-8s %s%s", $label, $verdict, $reason, PHP_EOL);
    }
}
