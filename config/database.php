<?php

declare(strict_types=1);

define('DB_HOST', getenv('BARBER_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('BARBER_DB_NAME') ?: 'barber_company');
define('DB_USER', getenv('BARBER_DB_USER') ?: 'root');
define('DB_PASS', getenv('BARBER_DB_PASS') ?: '');
date_default_timezone_set('Asia/Kolkata');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("SET time_zone = '+05:30'");
    return $pdo;
}
