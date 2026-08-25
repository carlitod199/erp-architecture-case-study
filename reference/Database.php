<?php

declare(strict_types=1);

namespace Reference;

use PDO;

/**
 * Database — a PDO factory with the settings that actually change behaviour.
 *
 * DECISION
 * One place builds every connection, and it builds it with four attributes that
 * are not defaults and that the rest of the codebase then depends on:
 *
 *   ERRMODE_EXCEPTION      A failed statement throws instead of returning false.
 *                          Without it, an ignored return value turns a broken
 *                          write into a silent no-op that surfaces days later as
 *                          missing data. With it, the request dies loudly and the
 *                          error boundary logs a stack trace.
 *   DEFAULT_FETCH_MODE     Associative arrays only. The numeric half of the
 *                          default BOTH mode doubles the size of every row in
 *                          memory and invites positional access that breaks the
 *                          moment a column is added.
 *   EMULATE_PREPARES=false Statements are prepared by the server, not
 *                          interpolated by the driver. See the trade-off below.
 *   STRINGIFY_FETCHES=false Combined with native prepares, integer and float
 *                          columns come back as PHP int/float instead of strings,
 *                          so `===` comparisons and json_encode output are honest.
 *
 * THE CONSEQUENCE OF DISABLING EMULATED PREPARES
 * This is the setting people flip without reading the fallout. Emulation off is
 * the safer default — the driver ships the SQL and the values to the server
 * separately, so a value can never be re-parsed as syntax, and the server sees
 * the real statement shape. But three things stop working the way people expect:
 *
 *   1. A named placeholder can be used ONLY ONCE per statement. `:tenant`
 *      appearing in both the outer query and a subquery raises "Invalid parameter
 *      number". You bind `:tenant` and `:tenant_inner` instead. Under emulation
 *      the driver silently expanded both, so the bug appears only after you turn
 *      emulation off — usually in production, on the one query with a subquery.
 *   2. Placeholders are values, never identifiers or clauses. `LIMIT :n` needs an
 *      explicit `bindValue(':n', $n, PDO::PARAM_INT)`; a table name or an
 *      `INTERVAL :days DAY` cannot be bound at all and has to be built from a
 *      validated integer or an allow-list.
 *   3. Types stop being coerced for you. Passing the string "3" to an integer
 *      column works, but passing "" to an integer column now fails loudly rather
 *      than becoming 0.
 *
 * TRADE-OFF ACCEPTED
 * A rule the whole codebase must remember (unique placeholder names, integers
 * bound explicitly) in exchange for statements that cannot be reshaped by data.
 * The rule is cheap to follow and its violations fail immediately and visibly;
 * the alternative fails quietly and occasionally.
 *
 * The MySQL session initialisation is deliberately per-connection: it changes
 * this session's time zone only, and never touches server-global configuration
 * that other tenants of the same shared host would inherit.
 */
final class Database
{
    /** Options every connection gets, regardless of driver. */
    public static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }

    /**
     * Generic connect. `$extra` may override the defaults above, but overriding
     * EMULATE_PREPARES should be a documented, local decision — not a habit.
     */
    public static function connect(
        string $dsn,
        ?string $user = null,
        ?string $password = null,
        array $extra = []
    ): PDO {
        return new PDO($dsn, $user, $password, $extra + self::options());
    }

    /**
     * MySQL connection built from a config array:
     *   ['host','port','database','user','password','charset','time_zone']
     *
     * charset belongs in the DSN, not in a `SET NAMES` statement: a `SET NAMES`
     * issued after connect leaves a window where the driver's idea of the
     * encoding and the server's disagree.
     */
    public static function mysql(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? '127.0.0.1',
            (int) ($config['port'] ?? 3306),
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );

        $pdo = self::connect($dsn, $config['user'] ?? null, $config['password'] ?? null);
        self::initialiseMysqlSession($pdo, (string) ($config['time_zone'] ?? '+00:00'));

        return $pdo;
    }

    /**
     * Per-session settings. Two things worth explaining:
     *
     * - The time zone is pinned per session because application servers and
     *   managed database instances routinely disagree about the wall clock, and
     *   a synchronisation cursor compared against a server-stamped `updated_at`
     *   is only correct if both sides agree which clock is authoritative. Pin it,
     *   and read "now" from the database when the value is going to be compared
     *   against database timestamps.
     * - The time zone offset is validated against a strict pattern before it is
     *   concatenated, because `SET time_zone` cannot take a placeholder. Any
     *   clause that cannot be parameterised must be built from validated input.
     */
    private static function initialiseMysqlSession(PDO $pdo, string $timeZone): void
    {
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $timeZone) !== 1) {
            throw new \InvalidArgumentException('time_zone must look like +00:00 or -03:00.');
        }

        $pdo->exec("SET time_zone = '{$timeZone}'");
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
    $pdo = Database::connect('sqlite::memory:');
    $pdo->exec('CREATE TABLE demo (id INTEGER PRIMARY KEY, label TEXT)');
    $insert = $pdo->prepare('INSERT INTO demo (label) VALUES (:label)');
    $insert->execute([':label' => "Robert'); DROP TABLE demo;--"]);

    $rows = $pdo->query('SELECT id, label FROM demo')->fetchAll();
    echo 'emulated prepares requested: ',
        var_export(Database::options()[PDO::ATTR_EMULATE_PREPARES], true), PHP_EOL;
    echo 'stored verbatim, table intact: ', $rows[0]['label'], PHP_EOL;
    echo 'id came back as ', get_debug_type($rows[0]['id']), PHP_EOL;
}
