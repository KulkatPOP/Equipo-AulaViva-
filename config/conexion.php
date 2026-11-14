<?php
// Credenciales inyectadas por entorno. Nunca incorporarlas a la imagen.
$dsn = getenv('DB_DSN');
if (!$dsn) {
    throw new RuntimeException('Falta configurar DB_DSN');
}
$pdo = new PDO($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
