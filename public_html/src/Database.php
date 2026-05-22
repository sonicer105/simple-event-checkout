<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class Database
{
    public static function connect(array $config): Connection
    {
        return DriverManager::getConnection([
            'dbname' => $config['name'],
            'user' => $config['user'],
            'password' => $config['pass'],
            'host' => $config['host'],
            'port' => $config['port'],
            'charset' => $config['charset'] ?? 'utf8mb4',
            'driver' => 'pdo_mysql',
        ]);
    }
}
