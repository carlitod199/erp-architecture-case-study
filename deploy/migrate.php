<?php
/* ============================================================================
   VERO — runner de migração para o JOB de migração (container).
   Usa config/database.php -> no JOB o .env deve ter as credenciais do vero_migrate.
   Aplica, em ORDEM NATURAL, rastreando em schema_migrations (idempotente):
     1) database/migrations/*.sql   (por CHECKSUM)
     2) migrations/migration_*.php  (por NOME — série ad-hoc, idempotente)
   Rodar: php deploy/migrate.php
   ANTES de rodar em produção, o wrapper do job deve tirar o snapshot ZFS:
     zfs snapshot vpool/vero/mysql@pre-migracao   (rollback de DDL ruim)
   Exit: 0 = ok · 1 = erro ao aplicar · 2 = não obteve lock · 3 = checksum divergente.
   ============================================================================ */
declare(strict_types=1);

$root = dirname(__DIR__);
$cfg  = require $root . '/config/database.php';
$pdo  = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['dbname'], $cfg['charset']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
echo "migrate: {$cfg['host']}/{$cfg['dbname']} como {$cfg['user']}\n";

/* (3) LOCK — impede dois jobs concorrentes de aplicar a mesma migração. */
if ((int)$pdo->query("SELECT GET_LOCK('vero_migracao', 10)")->fetchColumn() !== 1) {
    fwrite(STDERR, "ERRO: não obtive o lock 'vero_migracao' (outra migração em andamento?).\n");
    exit(2);
}

$exit = 0;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        arquivo     VARCHAR(190) NOT NULL PRIMARY KEY,
        checksum    CHAR(64)     NOT NULL,
        aplicada_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $aplicadas = [];
    foreach ($pdo->query("SELECT arquivo, checksum FROM schema_migrations") as $r) {
        $aplicadas[$r['arquivo']] = $r['checksum'];
    }
    $registra = $pdo->prepare("INSERT INTO schema_migrations (arquivo, checksum) VALUES (:a, :c)");

    /* (4) ordenação NATURAL, igual à série .php (sort -V). */
    $porNome = static fn(string $a, string $b): int => strnatcmp(basename($a), basename($b));

    /* ---- 1) database/migrations/*.sql (por checksum) ---- */
    $sqlFiles = glob($root . '/database/migrations/*.sql') ?: [];
    usort($sqlFiles, $porNome);
    $novasSql = 0; $avisos = 0;
    foreach ($sqlFiles as $f) {
        $base = basename($f);
        $sql  = (string)file_get_contents($f);
        $sum  = hash('sha256', $sql);
        if (isset($aplicadas[$base])) {
            if ($aplicadas[$base] !== $sum) {
                fwrite(STDERR, "AVISO: {$base} já aplicada, mas o ARQUIVO MUDOU (checksum difere) — NÃO reaplico. Crie uma migração NOVA.\n");
                $avisos++;   // (2) vira exit != 0 no fim
            }
            continue;
        }
        echo "-> sql: {$base}\n";
        /* (1) query()+nextRowset(): executa TODOS os statements e LANÇA em qualquer
           um que falhe (exec() engoliria erro fora do 1º statement). Só registra
           depois de esgotar sem exceção. */
        $st = $pdo->query($sql);
        if ($st instanceof PDOStatement) {
            do { /* consome cada result set — lança se um statement quebrar */ }
            while ($st->nextRowset());
            $st->closeCursor();
        }
        $registra->execute([':a' => $base, ':c' => $sum]);
        $novasSql++;
    }

    /* ---- 2) migrations/migration_*.php (série ad-hoc, rastreada por nome) ---- */
    $phpFiles = glob($root . '/migrations/migration_*.php') ?: [];
    usort($phpFiles, $porNome);
    $novasPhp = 0;
    foreach ($phpFiles as $f) {
        $chave = 'php:' . basename($f);
        if (isset($aplicadas[$chave])) continue;
        echo "-> php: " . basename($f) . "\n";
        require $f;   // idempotente (checa information_schema); abre seu próprio PDO
        $registra->execute([':a' => $chave, ':c' => hash('sha256', (string)file_get_contents($f))]);
        $novasPhp++;
    }

    echo "Migrações: .sql={$novasSql} nova(s), .php={$novasPhp} nova(s), {$avisos} aviso(s).\n";
    if ($avisos > 0) $exit = 3;   // (2) checksum divergente NÃO é sucesso
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO na migração: " . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    $pdo->query("SELECT RELEASE_LOCK('vero_migracao')");
}

echo $exit === 0 ? "OK.\n" : "FALHOU (exit {$exit}).\n";
exit($exit);
