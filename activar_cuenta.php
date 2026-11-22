<?php
require_once __DIR__ . '/config/activacion.php';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
    session_start();
}
$error = '';
$invitacion = false;
$metodo = $_SERVER['REQUEST_METHOD'];
if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST'); http_response_code(405); $error = 'Método no permitido.';
} else {
    try {
        if (!isset($pdo) || !($pdo instanceof PDO)) { require __DIR__ . '/config/conexion.php'; }
        if ($metodo === 'GET') {
            $token = $_GET['token'] ?? null;
            if (!tokenActivacionValido($token)) { throw new ErrorActivacion(400, 'Invitación no válida.'); }
            $hash = hash('sha256', $token);
            $invitacion = consultarInvitacionActivacion($pdo, $hash);
            $_SESSION['activacion_cuenta'] = ['hash' => $hash, 'csrf' => bin2hex(random_bytes(32))];
        } else {
            $estado = $_SESSION['activacion_cuenta'] ?? null;
            if (!is_array($estado) || !is_string($estado['hash'] ?? null) || !tokenActivacionValido($estado['hash'])
                || !is_string($estado['csrf'] ?? null) || !is_string($_POST['csrf_token'] ?? null)
                || !hash_equals($estado['csrf'], $_POST['csrf_token'])) {
                throw new ErrorActivacion(403, 'Solicitud no válida. Vuelve a abrir el enlace de invitación.');
            }
            $invitacion = consultarInvitacionActivacion($pdo, $estado['hash']);
            activarInvitacion($pdo, $estado['hash'], $_POST);
            unset($_SESSION['activacion_cuenta']);
            header('Location: login.php', true, 303);
            exit;
        }
    } catch (ErrorActivacion $e) {
        http_response_code($e->estadoHttp);
        $error = $e->getMessage();
    } catch (Throwable $e) {
        http_response_code(500);
        $error = 'No se pudo activar la cuenta. Inténtalo nuevamente.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear cuenta | AulaIA</title>
    <link rel="stylesheet" href="css/login.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>

    <header>
        <img src="img/Logo.png" alt="Logo AulaIA" class="logo-header">
        <h1>AulaIA</h1>
    </header>

    <main>

        <div class="login">

            <img src="img/Logo.png" alt="Logo AulaIA" class="logo-login">

            <h1>Crear cuenta</h1>

            <p class="subtitulo">
                Completa tus datos para activar tu cuenta
            </p>
            <?php if ($error !== ''): ?>

                <p class="error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </p>

            <?php endif; ?>

            <?php if ($invitacion): ?>
            <form method="POST" action="activar_cuenta.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['activacion_cuenta']['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <label for="nombre">Nombre</label>

                <input
                    type="text"
                    id="nombre"
                    name="nombre"
                    maxlength="120"
                    placeholder="Ingresa tu nombre"
                    required>

                <label for="apellido">Apellido</label>

                <input
                    type="text"
                    id="apellido"
                    name="apellido"
                    maxlength="120"
                    placeholder="Ingresa tu apellido"
                    required>

                <label for="email">Correo electrónico</label>

                <input
                    type="email"
                    id="email"
                    value="<?= htmlspecialchars($invitacion['email'], ENT_QUOTES, 'UTF-8') ?>"
                    disabled>

                <label for="username">Nombre de usuario</label>

                <input
                    type="text"
                    id="username"
                    value="<?= htmlspecialchars($invitacion['username'], ENT_QUOTES, 'UTF-8') ?>"
                    disabled>

                <label for="password">Contraseña</label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Crea una contraseña"
                    required>

                <label for="confirmar_password">Confirmar contraseña</label>

                <input
                    type="password"
                    id="confirmar_password"
                    name="confirmar_password"
                    placeholder="Repite tu contraseña"
                    required>

                <button type="submit">Crear cuenta</button>

            </form>
            <?php else: ?>
                <p><a href="login.php">Volver al inicio de sesión</a></p>
            <?php endif; ?>

        </div>

    </main>

</body>

</html>