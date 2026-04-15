<?php

declare(strict_types=1);

namespace SwooleLearn\ShortUrl\Infrastructure;

use PDO;

final class PdoFactory
{
    public static function fromEnv(): PDO
    {
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $database = getenv('MYSQL_DATABASE') ?: 'swoole_short_url';
        $username = getenv('MYSQL_USERNAME') ?: 'root';
        $password = getenv('MYSQL_PASSWORD') ?: 'root';
        $charset = getenv('MYSQL_CHARSET') ?: 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $database, $charset);

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
