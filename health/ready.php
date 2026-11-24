<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $dsn = getenv('DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'pgsql:')) {
        throw new RuntimeException();
    }
    // Dedicated non-persistent connection: do not alter application connections.
    // PDO pgsql maps ATTR_TIMEOUT to libpq connect_timeout (seconds).
    $pdo = @new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $pdo->exec('SET statement_timeout = 2000');
    if ((int) $pdo->query('SELECT 1')->fetchColumn() !== 1) {
        throw new RuntimeException();
    }
    http_response_code(200);
    echo "ok\n";
} catch (Throwable) {
    http_response_code(503);
    echo "unavailable\n";
} finally {
    $pdo = null;
}
