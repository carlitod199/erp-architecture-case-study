<?php

declare(strict_types=1);

/**
 * Dependency-free test runner for the reference implementations.
 *
 *   php reference/tests/run.php
 *
 * No framework, because the point of these files is that they have no
 * dependencies; a test suite that needs Composer would undercut that. It is a
 * few dozen lines of assertion plumbing and it exits non-zero on failure, which
 * is all a CI step needs.
 */

require __DIR__ . '/../Database.php';
require __DIR__ . '/../SessionGuard.php';
require __DIR__ . '/../LoginThrottle.php';
require __DIR__ . '/../SecretBox.php';
require __DIR__ . '/../Permissions.php';
require __DIR__ . '/../SecurityHeaders.php';
require __DIR__ . '/../ErrorBoundary.php';

use Reference\Database;
use Reference\ErrorBoundary;
use Reference\InMemoryAttemptStore;
use Reference\KeyRing;
use Reference\LoginThrottle;
use Reference\PermissionDenied;
use Reference\Permissions;
use Reference\SecretBox;
use Reference\SecurityHeaders;
use Reference\SessionGuard;

final class Runner
{
    private int $passed = 0;
    /** @var list<string> */
    private array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo PHP_EOL, $name, PHP_EOL, str_repeat('-', strlen($name)), PHP_EOL;
    }

    public function ok(string $what, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo '  PASS  ', $what, PHP_EOL;

            return;
        }

        $this->failures[] = $this->group . ' :: ' . $what . ($detail !== '' ? ' (' . $detail . ')' : '');
        echo '  FAIL  ', $what, $detail !== '' ? '  -- ' . $detail : '', PHP_EOL;
    }

    public function same(string $what, mixed $expected, mixed $actual): void
    {
        $this->ok(
            $what,
            $expected === $actual,
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }

    public function throws(string $what, callable $fn, string $class = \Throwable::class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->ok($what, $e instanceof $class, 'got ' . $e::class);

            return;
        }

        $this->ok($what, false, 'nothing was thrown');
    }

    public function summary(): int
    {
        echo PHP_EOL, str_repeat('=', 60), PHP_EOL;
        if ($this->failures === []) {
            echo 'ALL PASS  (', $this->passed, ' assertions)', PHP_EOL;

            return 0;
        }

        echo count($this->failures), ' FAILED, ', $this->passed, ' passed', PHP_EOL;
        foreach ($this->failures as $failure) {
            echo '  - ', $failure, PHP_EOL;
        }

        return 1;
    }
}

$t = new Runner();

/* ------------------------------------------------------------------ */
$t->group('SessionGuard');

$guard = new SessionGuard(idleTimeout: 60, absoluteTimeout: 600, rotateEvery: 30, rotationGrace: 10);
$now = 1_700_000_000;

$t->same('active session passes', [SessionGuard::OK, 'active'],
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now, '_rotated_from' => $now], $now));

$t->same('idle beyond the limit expires', [SessionGuard::EXPIRE, 'idle_timeout'],
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now - 61], $now));

$t->same('idle just inside the limit survives', SessionGuard::OK,
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now - 59, '_rotated_from' => $now], $now)[0]);

$t->same('absolute limit expires an active session', [SessionGuard::EXPIRE, 'absolute_timeout'],
    $guard->evaluate(['login_at' => $now - 601, 'last_activity' => $now], $now));

$t->same('rotation falls due on schedule', [SessionGuard::ROTATE, 'rotation_due'],
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now, '_rotated_from' => $now - 31], $now));

$t->same('in-flight request on the old id survives inside the grace window',
    [SessionGuard::OK, 'rotation_grace'],
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now, '_rotated_at' => $now - 9], $now));

$t->same('the old id stops working after the grace window',
    [SessionGuard::EXPIRE, 'rotated_away'],
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now, '_rotated_at' => $now - 11], $now));

$t->same('a rotated-away session is never told to rotate again', SessionGuard::OK,
    $guard->evaluate(['login_at' => $now, 'last_activity' => $now, '_rotated_at' => $now - 1, '_rotated_from' => $now - 999], $now)[0]);

$secure = $guard->cookieParams(true);
$t->ok('cookie is httponly', $secure['httponly'] === true);
$t->ok('cookie is secure over TLS', $secure['secure'] === true);
$t->ok('cookie is not secure without TLS (or it would never be sent)',
    $guard->cookieParams(false)['secure'] === false);
$t->same('cookie is SameSite=Lax', 'Lax', $secure['samesite']);
$t->same('cookie expires with the browser session', 0, $secure['lifetime']);
$t->ok('__Host- prefix only when the cookie can satisfy it',
    str_starts_with($guard->cookieName(true), '__Host-') && !str_starts_with($guard->cookieName(false), '__Host-'));

$t->ok('TLS seen behind a terminating proxy',
    SessionGuard::isHttps(['HTTP_X_FORWARDED_PROTO' => 'https']));
$t->ok('plain HTTP recognised as plain', !SessionGuard::isHttps(['HTTPS' => 'off', 'SERVER_PORT' => '80']));

/* ------------------------------------------------------------------ */
$t->group('LoginThrottle');

$clock = 2_000_000;
$store = new InMemoryAttemptStore(function () use (&$clock): int { return $clock; });
$throttle = new LoginThrottle($store, maxPairFailures: 3, maxIpFailures: 5, windowSeconds: 900, bcryptCost: 4);

$t->ok('a clean identity is not blocked', !$throttle->isBlocked('alice@example.test', '198.51.100.9'));

for ($i = 0; $i < 3; $i++) {
    $throttle->record('alice@example.test', '198.51.100.9', false);
}
$t->ok('the pair is blocked at the threshold', $throttle->isBlocked('alice@example.test', '198.51.100.9'));
$t->ok('the same identity from another address is NOT locked out (no lockout DoS)',
    !$throttle->isBlocked('alice@example.test', '203.0.113.4'));

// Distributed spray: one address, many identities, none of them hitting the pair limit.
$spread = new InMemoryAttemptStore(function () use (&$clock): int { return $clock; });
$sprayed = new LoginThrottle($spread, maxPairFailures: 3, maxIpFailures: 5, windowSeconds: 900, bcryptCost: 4);
for ($i = 0; $i < 5; $i++) {
    $sprayed->record('victim' . $i . '@example.test', '203.0.113.99', false);
}
$t->ok('a spraying address is blocked by the per-IP counter',
    $sprayed->isBlocked('victim9@example.test', '203.0.113.99'));
$t->ok('the accounts that were sprayed are not themselves locked out',
    !$sprayed->isBlocked('victim0@example.test', '198.51.100.20'));

$expired = new InMemoryAttemptStore(function () use (&$clock): int { return $clock; });
$aging = new LoginThrottle($expired, maxPairFailures: 3, windowSeconds: 900, bcryptCost: 4);
for ($i = 0; $i < 3; $i++) {
    $expired->record('bob@example.test', '198.51.100.9', false);
}
$clock += 901;
$t->ok('the block lifts once the window has passed', !$aging->isBlocked('bob@example.test', '198.51.100.9'));

final class BrokenStore implements \Reference\AttemptStore
{
    public function countPairFailures(string $identity, string $ip, int $windowSeconds): int
    {
        throw new \RuntimeException('attempt store is down');
    }

    public function countIpFailures(string $ip, int $windowSeconds): int
    {
        throw new \RuntimeException('attempt store is down');
    }

    public function record(string $identity, string $ip, bool $successful): void
    {
        throw new \RuntimeException('attempt store is down');
    }
}

$failClosed = new LoginThrottle(new BrokenStore(), bcryptCost: 4);
$t->ok('a broken attempt store fails CLOSED', $failClosed->isBlocked('alice@example.test', '198.51.100.9'));
$failClosed->record('alice@example.test', '198.51.100.9', true);
$t->ok('a broken attempt store cannot break a successful login', true);

$hash = $throttle->hash('correct horse battery staple');
$t->ok('the right password verifies', $throttle->verify($hash, 'correct horse battery staple'));
$t->ok('the wrong password does not', !$throttle->verify($hash, 'incorrect horse'));
$t->ok('a missing account verifies as false, not as an error', !$throttle->verify(null, 'anything'));

// Timing: the unknown-account path must do the same bcrypt work as the
// wrong-password path. Measured over several rounds and compared as a ratio,
// because absolute timings on a shared runner are noise.
$rounds = 12;
$known = 0.0;
$unknown = 0.0;
for ($i = 0; $i < $rounds; $i++) {
    $start = hrtime(true);
    $throttle->verify($hash, 'incorrect horse');
    $known += hrtime(true) - $start;

    $start = hrtime(true);
    $throttle->verify(null, 'incorrect horse');
    $unknown += hrtime(true) - $start;
}
$ratio = $unknown / max($known, 1);
$t->ok(
    'unknown account and wrong password cost the same order of work',
    $ratio > 0.2 && $ratio < 5.0,
    sprintf('ratio %.2F (known %.1Fms, unknown %.1Fms)', $ratio, $known / 1e6, $unknown / 1e6)
);

$cheap = new LoginThrottle(new InMemoryAttemptStore(), bcryptCost: 4);
$t->ok('a hash below the configured cost is flagged for re-hashing',
    (new LoginThrottle(new InMemoryAttemptStore(), bcryptCost: 6))->needsRehash($cheap->hash('x')));

/* ------------------------------------------------------------------ */
$t->group('SecretBox');

$k1 = base64_encode(random_bytes(32));
$k2 = base64_encode(random_bytes(32));

$box = new SecretBox(new KeyRing(['v1' => $k1], 'v1'), 'integration');
$envelope = $box->encrypt('client-secret-value', 'tenant:42');

$t->ok('the envelope is tagged and versioned', str_starts_with($envelope, 'box.v1.v1.'));
$t->ok('the plaintext does not appear in the envelope', !str_contains($envelope, 'client-secret-value'));
$t->same('round trip', 'client-secret-value', $box->decrypt($envelope, 'tenant:42'));

$again = $box->encrypt('client-secret-value', 'tenant:42');
$t->ok('the same plaintext encrypts to a different envelope each time (random nonce)', $again !== $envelope);

$t->throws('a ciphertext bound to another tenant will not open',
    fn () => $box->decrypt($envelope, 'tenant:43'), \RuntimeException::class);

$payload = substr($envelope, strlen('box.v1.v1.'));
$raw = base64_decode($payload, true);
$raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'A' ? 'B' : 'A';
$tampered = 'box.v1.v1.' . base64_encode($raw);
$t->throws('a tampered ciphertext is rejected, not decrypted to garbage',
    fn () => $box->decrypt($tampered, 'tenant:42'), \RuntimeException::class);

$t->throws('a truncated envelope is rejected',
    fn () => $box->decrypt('box.v1.v1.' . base64_encode('short'), 'tenant:42'), \RuntimeException::class);
$t->throws('a value that is not an envelope is rejected',
    fn () => $box->decrypt('plain text', 'tenant:42'), \RuntimeException::class);

$rotating = new SecretBox(new KeyRing(['v2' => $k2, 'v1' => $k1], 'v2'), 'integration');
$t->ok('an old envelope is flagged for rotation', $rotating->needsRotation($envelope));
$t->same('an old envelope still decrypts while its key is in the ring',
    'client-secret-value', $rotating->decrypt($envelope, 'tenant:42'));

$rotated = $rotating->rotate($envelope, 'tenant:42');
$t->ok('a rotated envelope names the current key', str_starts_with($rotated, 'box.v1.v2.'));
$t->ok('a rotated envelope no longer needs rotation', !$rotating->needsRotation($rotated));
$t->same('a rotated envelope holds the same secret',
    'client-secret-value', $rotating->decrypt($rotated, 'tenant:42'));

$dropped = new SecretBox(new KeyRing(['v2' => $k2], 'v2'), 'integration');
$t->throws('dropping a key that envelopes still name fails loudly',
    fn () => $dropped->decrypt($envelope, 'tenant:42'), \RuntimeException::class);

$other = new SecretBox(new KeyRing(['v1' => $k1], 'v1'), 'device-password');
$t->throws('a different purpose derives a different key',
    fn () => $other->decrypt($envelope, 'tenant:42'), \RuntimeException::class);

$t->throws('a key of the wrong length is refused',
    fn () => new KeyRing(['v1' => base64_encode(random_bytes(16))], 'v1'), \InvalidArgumentException::class);
$t->throws('a ring whose current key is missing is refused',
    fn () => new KeyRing(['v1' => $k1], 'v9'), \InvalidArgumentException::class);

putenv('APP_SECRET_KEYS_TEST=v2:' . $k2 . ',v1:' . $k1);
$fromEnv = KeyRing::fromEnv('APP_SECRET_KEYS_TEST');
$t->same('the first entry of the env ring is current', 'v2', $fromEnv->currentId());

$t->same('a masked secret shows only the tail', '********alue', SecretBox::mask('client-secret-value'));
$t->ok('a masked value is recognisable on the way back in',
    SecretBox::looksMasked(SecretBox::mask('client-secret-value')));

/* ------------------------------------------------------------------ */
$t->group('Permissions');

$manager = new Permissions(['inventory.*', 'finance.invoices.view'], 'manager');
$t->ok('a module wildcard covers an unlisted screen', $manager->allows('inventory.receipts.edit'));
$t->ok('a module wildcard covers the module slug itself', $manager->allows('inventory.view'));
$t->ok('an explicit grant is honoured', $manager->allows('finance.invoices.view'));
$t->ok('a neighbouring action is not granted by an explicit view grant',
    !$manager->allows('finance.invoices.edit'));
$t->ok('another module is not granted at all', !$manager->allows('payroll.runs.view'));

$screen = new Permissions(['inventory.receipts.*'], 'clerk');
$t->ok('a screen wildcard covers its actions', $screen->allows('inventory.receipts.delete'));
$t->ok('a screen wildcard does not leak to a sibling screen',
    !$screen->allows('inventory.transfers.delete'));

$auditor = new Permissions(['*.view'], 'auditor');
$t->ok('an action wildcard reads across modules', $auditor->allows('payroll.runs.view'));
$t->ok('an action wildcard grants nothing that writes', !$auditor->allows('payroll.runs.edit'));

$root = new Permissions(['*'], 'anything');
$t->ok('the total wildcard allows everything', $root->allows('payroll.runs.delete'));

$admin = new Permissions([], 'system_admin');
$t->ok('a superuser role bypasses the list', $admin->allows('payroll.runs.delete'));

$module = new Permissions(['inventory.view'], 'viewer');
$t->ok('seeing a module does NOT imply seeing its screens',
    !$module->allows('inventory.receipts.view'));

$t->ok('an empty permission is never allowed', !$root->allows(''));
$t->ok('allowsAny is an OR', $manager->allowsAny('payroll.runs.view', 'inventory.view'));
$t->ok('allowsAll is an AND',
    !$manager->allowsAll('inventory.view', 'payroll.runs.view'));

$t->throws('require() throws on a denied permission',
    fn () => $manager->require('payroll.runs.edit'), PermissionDenied::class);
$manager->require('inventory.view');
$t->ok('require() is silent on an allowed permission', true);

/* ------------------------------------------------------------------ */
$t->group('SecurityHeaders');

$plain = (new SecurityHeaders(https: false))->headers();
$tls = (new SecurityHeaders(https: true, enforceCsp: true))->headers();

$t->ok('HSTS is not sent over plaintext', !isset($plain['Strict-Transport-Security']));
$t->ok('HSTS is sent over TLS', isset($tls['Strict-Transport-Security']));
$t->ok('HSTS covers subdomains', str_contains($tls['Strict-Transport-Security'], 'includeSubDomains'));
$t->ok('HSTS does not silently opt into the preload list',
    !str_contains($tls['Strict-Transport-Security'], 'preload'));
$t->same('MIME sniffing is off', 'nosniff', $plain['X-Content-Type-Options']);
$t->ok('framing is restricted', isset($plain['X-Frame-Options']));
$t->ok('the referrer is trimmed cross-origin',
    $plain['Referrer-Policy'] === 'strict-origin-when-cross-origin');
$t->ok('device APIs are switched off', str_contains($plain['Permissions-Policy'], 'camera=()'));

$t->ok('the transitional policy is report-only',
    isset($plain['Content-Security-Policy-Report-Only']) && !isset($plain['Content-Security-Policy']));
$t->ok('the enforced policy is enforced',
    isset($tls['Content-Security-Policy']) && !isset($tls['Content-Security-Policy-Report-Only']));

$nonce = SecurityHeaders::newNonce();
$strict = new SecurityHeaders(https: true, enforceCsp: true, nonce: $nonce);
$policy = $strict->contentSecurityPolicy();
$t->ok('a nonce policy drops unsafe-inline from script-src',
    str_contains($policy, "'nonce-" . $nonce . "'") && !str_contains($policy, "script-src 'self' 'unsafe-inline'"));
$t->ok('object-src is none everywhere', str_contains($policy, "object-src 'none'"));
$t->ok('form-action is pinned to self', str_contains($policy, "form-action 'self'"));
$t->ok('two nonces are never the same', SecurityHeaders::newNonce() !== SecurityHeaders::newNonce());
$t->ok('TLS is detected through a proxy header',
    isset(SecurityHeaders::forRequest(['HTTP_X_FORWARDED_PROTO' => 'https'])->headers()['Strict-Transport-Security']));

/* ------------------------------------------------------------------ */
$t->group('ErrorBoundary');

$t->ok('an /api/ path is JSON whatever the Accept header says',
    ErrorBoundary::wantsJson(['REQUEST_URI' => '/api/v1/items', 'HTTP_ACCEPT' => 'text/html']));
$t->ok('an in-page fetch is JSON',
    ErrorBoundary::wantsJson(['REQUEST_URI' => '/items', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']));
$t->ok('a browser navigation is HTML',
    !ErrorBoundary::wantsJson(['REQUEST_URI' => '/items', 'HTTP_ACCEPT' => 'text/html,application/json']));
$t->ok('a bare JSON Accept is JSON',
    ErrorBoundary::wantsJson(['REQUEST_URI' => '/items', 'HTTP_ACCEPT' => 'application/json']));

$json = new ErrorBoundary(expectsJson: true);
$html = new ErrorBoundary(expectsJson: false);

$decoded = json_decode($json->body(false), true);
$t->ok('a JSON caller gets a parseable envelope', is_array($decoded));
$t->same('the envelope reports failure', false, $decoded['ok'] ?? null);
$t->same('the error code is stable', 'internal_error', $decoded['error'] ?? null);
$t->same('the correlation id is in the body', $json->correlationId(), $decoded['id'] ?? null);
$t->ok('no stack trace or file path reaches the caller',
    !str_contains($json->body(false), __FILE__) && !str_contains($json->body(false), 'Stack trace'));

$page = $html->body(false);
$t->ok('a browser gets a complete document',
    str_starts_with($page, '<!doctype html>') && str_ends_with($page, '</html>'));
$t->ok('the page carries the correlation id for a support call',
    str_contains($page, $html->correlationId()));
$t->ok('the page is not painted for one theme only', str_contains($page, 'color-scheme'));

$partial = $html->body(true);
$t->ok('a mid-render failure says so in the page', str_contains($partial, 'role="alert"'));
$t->ok('a mid-render failure closes the document rather than leaving it open',
    str_ends_with($partial, '</html>'));
$t->ok('a mid-render failure does not emit a second <!doctype>',
    !str_contains($partial, '<!doctype'));

$brokenJson = $json->body(true);
$t->ok('a truncated JSON response is left deliberately unparseable',
    json_decode('{"partial": true' . $brokenJson, true) === null);
$t->ok('the truncated marker still names the correlation id',
    str_contains($brokenJson, $json->correlationId()));

$logged = [];
$captured = new ErrorBoundary(expectsJson: true, logger: function (string $line) use (&$logged): void {
    $logged[] = $line;
});
try {
    // The error handler installed by install() is what turns this into an
    // exception; here we assert the log format the handler feeds on.
    throw new \RuntimeException('database is on fire');
} catch (\RuntimeException $e) {
    $reflection = new \ReflectionMethod($captured, 'log');
    $reflection->invoke($captured, $e);
}
$t->ok('the log line carries the exception class, message and correlation id',
    count($logged) === 1
    && str_contains($logged[0], 'RuntimeException')
    && str_contains($logged[0], 'database is on fire')
    && str_contains($logged[0], $captured->correlationId()));

/* ------------------------------------------------------------------ */
$t->group('Database');

$options = Database::options();
$t->same('errors throw instead of returning false',
    PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
$t->same('rows come back keyed by column name only',
    PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
$t->same('prepares are native, not emulated', false, $options[PDO::ATTR_EMULATE_PREPARES]);
$t->same('numbers are not stringified', false, $options[PDO::ATTR_STRINGIFY_FETCHES]);

$pdo = Database::connect('sqlite::memory:');
$pdo->exec('CREATE TABLE item (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, label TEXT NOT NULL)');

$insert = $pdo->prepare('INSERT INTO item (tenant_id, label) VALUES (:tenant_id, :label)');
$insert->execute([':tenant_id' => 1, ':label' => "Robert'); DROP TABLE item;--"]);
$insert->execute([':tenant_id' => 2, ':label' => 'other tenant']);

$select = $pdo->prepare('SELECT id, tenant_id, label FROM item WHERE tenant_id = :tenant_id');
$select->execute([':tenant_id' => 1]);
$rows = $select->fetchAll();

$t->same('a hostile value is stored verbatim, not executed', 1, count($rows));
$t->same('and it round trips unchanged', "Robert'); DROP TABLE item;--", $rows[0]['label']);
$t->same('the table is still there', 2, (int) $pdo->query('SELECT COUNT(*) FROM item')->fetchColumn());
$t->ok('no numeric duplicate keys in the row', !array_key_exists(0, $rows[0]));

$t->throws('a failed statement throws',
    fn () => $pdo->query('SELECT * FROM table_that_does_not_exist'), \PDOException::class);

$t->throws('an unsafe time zone string is refused rather than concatenated',
    function (): void {
        $method = new \ReflectionMethod(Database::class, 'initialiseMysqlSession');
        $method->invoke(null, Database::connect('sqlite::memory:'), "+00:00'; DROP TABLE x;--");
    },
    \InvalidArgumentException::class
);

exit($t->summary());
