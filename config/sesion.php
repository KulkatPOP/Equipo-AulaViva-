<?php

/* Configuración segura de sesión */

if (session_status() === PHP_SESSION_NONE) {

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

/* Verificar autenticación */

if (
    !isset(
        $_SESSION['usuario_id'],
        $_SESSION['colegio_id'],
        $_SESSION['rol']
    )
) {
    header('Location: /login.php');
    exit;
}

/* Verificar que la cuenta continúa autorizada */

try {

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require __DIR__ . '/conexion.php';
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND colegio_id = ?
          AND rol = ?
          AND activo = TRUE
    ");

    $stmt->execute([
        (int) $_SESSION['usuario_id'],
        (int) $_SESSION['colegio_id'],
        $_SESSION['rol']
    ]);

    $usuarioActivo = $stmt->fetchColumn() !== false;

} catch (Throwable $e) {

    error_log('Validación de sesión: ' . $e->getMessage());

    http_response_code(503);
    exit('Servicio temporalmente no disponible.');
}

/* Invalidar sesiones de cuentas no autorizadas */

if (!$usuarioActivo) {

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax'
        ]);
    }

    session_destroy();

    header('Location: /login.php');
    exit;
}
