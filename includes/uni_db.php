<?php
declare(strict_types=1);
/* ============================================================
   VERO — includes/uni_db.php
   PDO do banco SEPARADO da Universidade VERO (config/database_uni.php).
   Espelha includes/db.php (Database), mas é uma conexão INDEPENDENTE:
   a Universidade nunca compartilha o banco do sistema. Auth/RBAC
   continuam vindo do $_SESSION (populado por includes/auth.php no web
   ou api/v1/nucleo/contexto.php no app) — este arquivo só serve conteúdo.
   ============================================================ */

require_once __DIR__ . '/bootstrap.php';

class UniDatabase
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../config/database_uni.php';

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['dbname'],
                $config['charset']
            );

            self::$instance = new PDO(
                $dsn,
                $config['user'],
                $config['pass'],
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );

            self::$instance->exec("SET time_zone = '-03:00'");
        }

        return self::$instance;
    }
}

/** PDO do banco da Universidade (paralelo ao vero_pdo() do sistema). */
function uni_pdo(): PDO
{
    return UniDatabase::getConnection();
}
