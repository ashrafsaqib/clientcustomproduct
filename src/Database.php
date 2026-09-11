<?php

declare(strict_types=1);

class Database
{
    private static ?PDO $connection = null;

    public static function connection(array $config): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        if (empty($config['db_name']) || empty($config['db_username'])) {
            throw new RuntimeException('OpenCart database credentials are not configured (check config.php or .env).');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        );

        self::$connection = new PDO($dsn, $config['db_username'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }

    public static function prefix(array $config): string
    {
        return (string)($config['db_prefix'] ?? 'oc_');
    }
}
