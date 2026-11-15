<?php

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET' && $metodo !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cache_limiter', '');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$tokenRecibido = $metodo === 'GET' && array_key_exists('token', $_GET);
if ($tokenRecibido) {
    $token = $_GET['token'];
    if (!is_string($token) || !preg_match('/\A[0-9a-f]{64}\z/', $token)) {
        http_response_code(400);
        exit('Invitación no válida.');
    }
    $tokenHash = hash('sha256', $token);
} else {
    $tokenHash = $_SESSION['activacion_token_hash'] ?? null;
    if ($tokenHash === null) {
        http_response_code($metodo === 'POST' ? 403 : 400);
        exit('Invitación no válida. Abre el enlace de activación.');
    }
}

$csrf = $_SESSION['activacion_csrf'] ?? null;
if ($metodo === 'POST') {
    $tokenFormulario = $_POST['csrf_token'] ?? null;
    if (!is_string($tokenFormulario) || !is_string($csrf) || !hash_equals($csrf, $tokenFormulario)) {
        http_response_code(403);
        exit('El formulario no es válido. Vuelve a abrir el enlace de activación.');
    }
}

// La exclusión mutua de la activación corresponde a PostgreSQL.
session_write_close();

function cargarInvitacion(PDO $pdo, string $tokenHash, bool $bloquear = false): array
{
    $sql = 'SELECT id, colegio_id, email, rut, username, rol, usado,
                   expira_en <= clock_timestamp() AS vencida
            FROM invitaciones WHERE token_hash = :token_hash';
    if ($bloquear) {
        // Evalúa el vencimiento tras esperar el bloqueo de la fila.
        $sql = "SELECT invitacion.*, invitacion.expira_en <= clock_timestamp() AS vencida
                FROM (
                    SELECT id, colegio_id, email, rut, username, rol, usado, expira_en
                    FROM invitaciones WHERE token_hash = :token_hash FOR UPDATE
                ) AS invitacion";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':token_hash' => $tokenHash]);
    $invitacion = $stmt->fetch();

    if (!$invitacion || !in_array($invitacion['rol'], ['alumno', 'profesor'], true)) {
        throw new DomainException('Invitación no válida.', 400);
    }
    if ($invitacion['usado']) {
        throw new DomainException('Esta invitación ya fue utilizada.', 409);
    }
    if ($invitacion['vencida']) {
        throw new DomainException('Esta invitación ha expirado.', 410);
    }

    return $invitacion;
}

try {
    require_once __DIR__ . '/config/conexion.php';
    $invitacion = cargarInvitacion($pdo, $tokenHash);
} catch (DomainException $e) {
    http_response_code($e->getCode());
    exit($e->getMessage());
} catch (RuntimeException $e) {
    http_response_code(500);
    exit('No se pudo comprobar la invitación. Inténtalo nuevamente.');
}

if ($tokenRecibido) {
    session_start();
    $_SESSION['activacion_token_hash'] = $tokenHash;
    $_SESSION['activacion_csrf'] = bin2hex(random_bytes(32));
    header('Location: activar_cuenta.php', true, 303);
    exit;
}

$error = '';
if ($metodo === 'POST') {
    $nombre = $_POST['nombre'] ?? null;
    $apellido = $_POST['apellido'] ?? null;
    $password = $_POST['password'] ?? null;
    $confirmacion = $_POST['confirmar_password'] ?? null;

    if (!is_string($nombre) || !is_string($apellido) || !is_string($password) || !is_string($confirmacion)) {
        $error = 'Debes completar todos los campos.';
    } elseif (trim($nombre) === '' || trim($apellido) === '' || $password === '' || $confirmacion === '') {
        $error = 'Debes completar todos los campos.';
    } elseif ($password !== $confirmacion) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } elseif (strlen($password) > 72 || str_contains($password, "\0")) {
        $error = 'La contraseña debe ocupar hasta 72 bytes y no contener caracteres nulos.';
    }

    if ($error !== '') {
        http_response_code(422);
    } else {
        $mensajeDuplicado = 'No se pudo activar la cuenta con esta invitación. Contacta a tu establecimiento.';
        try {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->beginTransaction();
            $invitacion = cargarInvitacion($pdo, $tokenHash, true);

            $stmt = $pdo->prepare(
                'SELECT 1 FROM usuarios WHERE rut = :rut OR username = :username LIMIT 1'
            );
            $stmt->execute([':rut' => $invitacion['rut'], ':username' => $invitacion['username']]);
            if ($stmt->fetchColumn()) {
                throw new DomainException($mensajeDuplicado, 409);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (colegio_id, rut, username, nombre, apellido, password_hash, rol, activo)
                 VALUES (:colegio_id, :rut, :username, :nombre, :apellido, :password_hash, :rol, TRUE)'
            );
            $stmt->execute([
                ':colegio_id' => $invitacion['colegio_id'],
                ':rut' => $invitacion['rut'],
                ':username' => $invitacion['username'],
                ':nombre' => trim($nombre),
                ':apellido' => trim($apellido),
                ':password_hash' => $passwordHash,
                ':rol' => $invitacion['rol'],
            ]);

            $stmt = $pdo->prepare('UPDATE invitaciones SET usado = TRUE WHERE id = :id');
            $stmt->execute([':id' => $invitacion['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se consumió la invitación.');
            }
            $pdo->commit();
        } catch (DomainException | RuntimeException | ValueError $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof DomainException) {
                http_response_code($e->getCode());
                $error = $e->getMessage();
            } elseif ($e instanceof PDOException && $e->getCode() === '23505') {
                http_response_code(409);
                $error = $mensajeDuplicado;
            } else {
                http_response_code(500);
                $error = 'No se pudo activar la cuenta. Inténtalo nuevamente.';
            }
        }

        if ($error === '') {
            session_start();
            if (($_SESSION['activacion_token_hash'] ?? null) === $tokenHash) {
                unset($_SESSION['activacion_token_hash'], $_SESSION['activacion_csrf']);
            }
            $_SESSION['cuenta_activada'] = true;
            header('Location: login.php', true, 303);
            exit;
        }
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

            <form method="POST" action="activar_cuenta.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <label for="nombre">Nombre</label>

                <input
                    type="text"
                    id="nombre"
                    name="nombre"
                    placeholder="Ingresa tu nombre"
                    required>

                <label for="apellido">Apellido</label>

                <input
                    type="text"
                    id="apellido"
                    name="apellido"
                    placeholder="Ingresa tu apellido"
                    required>

                <label>Correo electrónico</label>

                <input
                    type="email"
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

        </div>

    </main>

</body>

</html>