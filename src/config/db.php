<?php
declare(strict_types=1);

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host = getenv('DB_HOST') ?: 'db';
        $name = getenv('DB_NAME') ?: 'zkteco';
        $user = getenv('DB_USER') ?: 'zkteco';
        $pass = getenv('DB_PASS') ?: 'zkteco_pass';

        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}
