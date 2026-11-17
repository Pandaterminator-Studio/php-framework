<?php

namespace Core;
use PDO;
use Core\Database\Connection;

class Model
{
    private static ?Connection $connection = null;

    public static function setConnection(Connection $connection): void
    {
        self::$connection = $connection;
    }

    protected static function getDB(): PDO {
        if (self::$connection === null) {
            if (class_exists(\App\Config::class) && is_callable([\App\Config::class, 'database'])) {
                $settings = \App\Config::database();
            } else {
                $driver = getenv('IRIS_DB_DRIVER') ?: 'mysql';
                $database = getenv('IRIS_DB_DATABASE');
                $dsn = getenv('IRIS_DB_DSN');
                if ($database === false && $dsn === false) {
                    throw new \RuntimeException('Inject a model connection or configure the database environment.');
                }
                $settings = [
                    'driver' => $driver,
                    'database' => $database === false ? null : $database,
                    'host' => getenv('IRIS_DB_HOST') ?: '127.0.0.1',
                    'port' => getenv('IRIS_DB_PORT') ?: 3306,
                    'username' => getenv('IRIS_DB_USER') ?: '',
                    'password' => getenv('IRIS_DB_PASSWORD') ?: '',
                ];
                if ($dsn !== false) {
                    $settings['dsn'] = $dsn;
                }
            }
            self::$connection = new Connection($settings);
        }
        return self::$connection->pdo();
    }
}
