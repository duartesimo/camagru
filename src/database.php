<?php

declare(strict_types=1);

function database_connection(): PDO
{
    static $connection = null;

    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASSWORD');

    if ($host === false || $host === '' || $name === false || $name === ''
        || $user === false || $user === '' || $password === false || $password === '') {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    try {
        $connection = new PDO(
            "pgsql:host={$host};dbname={$name}",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException) {
        throw new RuntimeException('Database connection unavailable.');
    }

    return $connection;
}
