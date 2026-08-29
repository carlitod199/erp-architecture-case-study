<?php
require_once __DIR__ . '/bootstrap.php';

class Database
{
    private static $instance = null;

    public static function getConnection()
    {
        if (self::$instance === null) {

            $config = require __DIR__ . '/../config/database.php';

            $dsn = sprintf(
                "mysql:host=%s;dbname=%s;charset=%s",
                $config['host'],
                $config['dbname'],
                $config['charset']
            );

            self::$instance = new PDO(
                $dsn,
                $config['user'],
                $config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            /*
             * Produção MySQL 8 vem com ONLY_FULL_GROUP_BY ativo.
             * Algumas queries legadas do VERO ainda usam GROUP BY flexível.
             * Ajuste aplicado por sessão, sem alterar o MySQL global.
             */
            self::$instance->exec("
                SET SESSION sql_mode = TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(@@SESSION.sql_mode,
                    'ONLY_FULL_GROUP_BY,', ''),
                    ',ONLY_FULL_GROUP_BY', ''),
                    'ONLY_FULL_GROUP_BY', ''
                ))
            ");

            self::$instance->exec("SET time_zone = '-03:00'");
        }

        return self::$instance;
    }
}

$pdo = Database::getConnection();
