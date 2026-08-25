<?php

declare(strict_types=1);

namespace Reference;

use PDO;

/**
 * LoginThrottle — brute-force limits and timing normalisation on the login path.
 *
 * THREE PROBLEMS, ONE FILE
 *
 * 1. Unlimited guessing. Without a limit, a login form is an offline password
 *    cracker with a network delay.
 *
 * 2. Lockout as a denial of service. Counting failures per ACCOUNT only means
 *    anyone who knows an operator's email address can lock that operator out of
 *    their own system by failing five logins. So the primary counter is keyed on
 *    (identity, ip): the attacker locks out only their own source. A second,
 *    looser counter on the IP alone catches the distributed case, one attacker
 *    spraying many accounts from one address.
 *
 * 3. The timing oracle. `SELECT ... WHERE email = ?` followed by
 *    `password_verify()` takes visibly longer when the account exists, because
 *    bcrypt only runs on the existing-account branch. That difference is
 *    measurable over the network and turns the login form into an account
 *    enumeration endpoint, which matters even when the error text is identical.
 *    The fix is to spend the same work either way: when there is no account (or
 *    the throttle already refused the attempt), verify the submitted password
 *    against a dummy hash of the same cost and throw the result away.
 *
 * FAIL-CLOSED
 * If the attempt store itself errors, the throttle cannot know how many attempts
 * have happened, and the safe answer is to refuse. An unavailable audit table is
 * an anomaly that should be short; unlimited unthrottled guessing is not
 * something to hand out because a query failed. This is the rare case where
 * availability loses on purpose — and it is worth saying out loud in review,
 * because it means a broken log table can lock everyone out.
 *
 * TRADE-OFF ACCEPTED
 * Storing attempts in the same relational database as everything else costs two
 * indexed COUNT queries per login. A dedicated counter store would be cheaper,
 * but on shared hosting with no Redis available, the database is the only store
 * there is — and the same rows double as the security audit trail, which has to
 * be written anyway.
 */
interface AttemptStore
{
    /** Failures for this exact (identity, ip) pair inside the window. */
    public function countPairFailures(string $identity, string $ip, int $windowSeconds): int;

    /** Failures from this ip against ANY identity inside the window. */
    public function countIpFailures(string $ip, int $windowSeconds): int;

    public function record(string $identity, string $ip, bool $successful): void;
}

final class LoginThrottle
{
    private string $dummyHash;

    public function __construct(
        private AttemptStore $store,
        private int $maxPairFailures = 5,
        private int $maxIpFailures = 20,
        private int $windowSeconds = 900,
        private int $bcryptCost = 12,
        ?string $dummyHash = null
    ) {
        // A pinned constant avoids paying for a hash on every boot, but it has to
        // be generated at the same cost as the real ones or the timing it exists
        // to hide leaks through the difference. Deriving it once per process from
        // a random password is one extra hash and cannot drift out of sync with
        // the configured cost, so that is the default here.
        $this->dummyHash = $dummyHash
            ?? password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }

    /**
     * Should this attempt be refused before any credential is checked?
     * Call this FIRST, and still equalise the timing on the refusal path — a
     * throttled response that returns instantly is its own oracle.
     */
    public function isBlocked(string $identity, string $ip): bool
    {
        try {
            if ($this->store->countPairFailures($identity, $ip, $this->windowSeconds) >= $this->maxPairFailures) {
                return true;
            }

            return $this->store->countIpFailures($ip, $this->windowSeconds) >= $this->maxIpFailures;
        } catch (\Throwable $e) {
            error_log('[login-throttle] store unavailable, failing closed: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Verify a password against a hash that may not exist. Both branches perform
     * exactly one bcrypt operation at the same cost, so a missing account and a
     * wrong password take the same time.
     */
    public function verify(?string $storedHash, string $password): bool
    {
        if ($storedHash === null || $storedHash === '') {
            password_verify($password, $this->dummyHash);

            return false;
        }

        return password_verify($password, $storedHash);
    }

    /** Burn one bcrypt of work on a path that never reaches verify() at all. */
    public function equaliseTiming(string $password): void
    {
        password_verify($password, $this->dummyHash);
    }

    public function record(string $identity, string $ip, bool $successful): void
    {
        try {
            $this->store->record($identity, $ip, $successful);
        } catch (\Throwable $e) {
            // Recording must never break a successful login; the counter degrades,
            // the user does not.
            error_log('[login-throttle] could not record attempt: ' . $e->getMessage());
        }
    }

    /** Re-hash on successful login when the configured cost has moved on. */
    public function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $this->bcryptCost]);
    }

    /**
     * The message is identical for "no such account", "wrong password", and
     * "account disabled". Differentiating them is a courtesy to attackers and
     * makes no difference to a legitimate user, who knows which of the three
     * applies to them.
     */
    public const GENERIC_FAILURE = 'Email or password is incorrect.';
    public const GENERIC_THROTTLED = 'Too many attempts. Try again in a few minutes.';
}

/**
 * Attempt store backed by an audit table. The same rows serve the security log,
 * so nothing is written twice:
 *
 *   CREATE TABLE auth_attempts (
 *     id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
 *     identity    VARCHAR(190) NOT NULL,
 *     ip          VARBINARY(45) NOT NULL,
 *     successful  TINYINT(1) NOT NULL,
 *     user_agent  VARCHAR(200) NULL,
 *     created_at  DATETIME NOT NULL,
 *     KEY idx_pair (identity, ip, successful, created_at),
 *     KEY idx_ip   (ip, successful, created_at)
 *   );
 *
 * Note how the window is applied: a validated integer is interpolated into the
 * INTERVAL clause, because with emulated prepares disabled an INTERVAL cannot
 * take a placeholder. It is safe only because the value comes from a constructor
 * argument cast to int and never from the request — the kind of exception that
 * has to be commented every single time it is made.
 */
final class PdoAttemptStore implements AttemptStore
{
    public function __construct(private PDO $pdo, private string $table = 'auth_attempts')
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $this->table) !== 1) {
            throw new \InvalidArgumentException('Unsafe table name.');
        }
    }

    public function countPairFailures(string $identity, string $ip, int $windowSeconds): int
    {
        $seconds = (int) $windowSeconds;
        $sql = "SELECT COUNT(*) FROM {$this->table}
                 WHERE successful = 0 AND identity = :identity AND ip = :ip
                   AND created_at >= (NOW() - INTERVAL {$seconds} SECOND)";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([':identity' => $identity, ':ip' => $ip]);

        return (int) $statement->fetchColumn();
    }

    public function countIpFailures(string $ip, int $windowSeconds): int
    {
        $seconds = (int) $windowSeconds;
        $sql = "SELECT COUNT(*) FROM {$this->table}
                 WHERE successful = 0 AND ip = :ip
                   AND created_at >= (NOW() - INTERVAL {$seconds} SECOND)";

        $statement = $this->pdo->prepare($sql);
        $statement->execute([':ip' => $ip]);

        return (int) $statement->fetchColumn();
    }

    public function record(string $identity, string $ip, bool $successful): void
    {
        $this->pdo->prepare(
            "INSERT INTO {$this->table} (identity, ip, successful, created_at)
             VALUES (:identity, :ip, :successful, NOW())"
        )->execute([
            ':identity'   => mb_substr($identity, 0, 190),
            ':ip'         => $ip,
            ':successful' => $successful ? 1 : 0,
        ]);
    }
}

/** In-memory store, for tests and for reading the policy at a glance. */
final class InMemoryAttemptStore implements AttemptStore
{
    /** @var list<array{identity:string,ip:string,successful:bool,at:int}> */
    private array $rows = [];

    public function __construct(private ?\Closure $clock = null)
    {
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }

    public function countPairFailures(string $identity, string $ip, int $windowSeconds): int
    {
        return $this->count(
            fn (array $r): bool => !$r['successful'] && $r['identity'] === $identity && $r['ip'] === $ip,
            $windowSeconds
        );
    }

    public function countIpFailures(string $ip, int $windowSeconds): int
    {
        return $this->count(fn (array $r): bool => !$r['successful'] && $r['ip'] === $ip, $windowSeconds);
    }

    public function record(string $identity, string $ip, bool $successful): void
    {
        $this->rows[] = [
            'identity'   => $identity,
            'ip'         => $ip,
            'successful' => $successful,
            'at'         => $this->now(),
        ];
    }

    private function count(callable $matches, int $windowSeconds): int
    {
        $cutoff = $this->now() - $windowSeconds;
        $total = 0;
        foreach ($this->rows as $row) {
            if ($row['at'] >= $cutoff && $matches($row)) {
                $total++;
            }
        }

        return $total;
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $store = new InMemoryAttemptStore();
    $throttle = new LoginThrottle($store, maxPairFailures: 3, bcryptCost: 4);

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $blocked = $throttle->isBlocked('operator@example.test', '203.0.113.7');
        printf("attempt %d blocked=%s%s", $attempt, $blocked ? 'yes' : 'no', PHP_EOL);
        if (!$blocked) {
            $throttle->record('operator@example.test', '203.0.113.7', false);
        }
    }

    $hash = $throttle->hash('correct horse');
    $known = microtime(true);
    $throttle->verify($hash, 'wrong password');
    $known = microtime(true) - $known;

    $unknown = microtime(true);
    $throttle->verify(null, 'wrong password');
    $unknown = microtime(true) - $unknown;

    printf("wrong password %.4Fs vs unknown account %.4Fs%s", $known, $unknown, PHP_EOL);
}
