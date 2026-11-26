<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';

exigirRol('admin');
header('Cache-Control: private, no-store');

$adminId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

$nombre = $_SESSION['nombre'] ?? 'Administrador';

$roles = [
    'profesor' => 'Profesor',
    'alumno' => 'Estudiante',
    'apoderado' => 'Apoderado',
    'admin' => 'Administrador'
];

if (!isset($_SESSION['csrf_admin_usuarios'])) {
    $_SESSION['csrf_admin_usuarios'] = bin2hex(random_bytes(32));
}

$mensaje = $_SESSION['mensaje_admin_usuarios'] ?? '';
unset($_SESSION['mensaje_admin_usuarios']);

$error = '';
$usuarios = [];

try {

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require __DIR__ . '/config/conexion.php';
    }

    // Cambiar estado de una cuenta.

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $csrf = $_POST['csrf_token'] ?? null;

        if (
            !is_string($csrf) ||
            !hash_equals($_SESSION['csrf_admin_usuarios'], $csrf)
        ) {
            http_response_code(403);
            exit('Solicitud no válida.');
        }

        $usuarioId = filter_var(
            $_POST['usuario_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $accion = $_POST['accion'] ?? '';

        if (
            !$usuarioId ||
            $usuarioId === $adminId ||
            !in_array($accion, ['activar', 'desactivar'], true)
        ) {
            http_response_code(400);
            exit('Operación no válida.');
        }

        $pdo->beginTransaction();

        // Confirmar autorización del administrador.

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE id = ?
              AND colegio_id = ?
              AND rol = 'admin'
              AND activo = TRUE
            FOR SHARE
        ");

        $stmt->execute([$adminId, $colegioId]);

        if ($stmt->fetchColumn() === false) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Acceso denegado.');
        }

        // Solo usuarios del mismo establecimiento.

        $estado = $accion === 'activar' ? 'true' : 'false';

        $stmt = $pdo->prepare("
            UPDATE usuarios
            SET activo = CAST(? AS boolean)
            WHERE id = ?
              AND colegio_id = ?
              AND id <> ?
              AND rol IN ('profesor', 'alumno', 'apoderado')
            RETURNING id
        ");

        $stmt->execute([
            $estado,
            $usuarioId,
            $colegioId,
            $adminId
        ]);

        if ($stmt->fetchColumn() === false) {
            $pdo->rollBack();
            http_response_code(404);
            exit('Usuario no disponible.');
        }

        $pdo->commit();

        $_SESSION['mensaje_admin_usuarios'] =
            $accion === 'activar'
                ? 'Usuario activado correctamente.'
                : 'Usuario desactivado correctamente.';

        header('Location: usuarios_admin.php', true, 303);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }

    // Obtener usuarios del establecimiento.

    $stmt = $pdo->prepare("
        SELECT
            id,
            nombre,
            apellido,
            username,
            rut,
            rol,
            activo
        FROM usuarios
        WHERE colegio_id = ?
        ORDER BY nombre, apellido, id
    ");

    $stmt->execute([$colegioId]);

    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Gestión de usuarios: ' . $e->getMessage());

    http_response_code(500);
    $error = 'No se pudo completar la operación.';
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Usuarios | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/admin.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
    <script src="js/usuarios-admin.js" defer></script>

</head>

<body class="av-dashboard">

    <!-- Encabezado -->

    <header class="av-header">

        <div class="av-header-left">

            <button type="button"
                    class="av-menu-button"
                    id="abrir-menu"
                    aria-label="Abrir menú"
                    aria-controls="menu-lateral"
                    aria-expanded="false">

                <svg viewBox="0 0 24 24"
                     width="24" height="24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>

            </button>

            <a href="panel_admin.php" class="av-logo">
                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>
            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">
                <strong><?= escaparApoderados($nombre) ?></strong>
                <span>Administrador</span>
            </div>

            <div class="av-avatar" aria-hidden="true">

                <svg viewBox="0 0 24 24"
                     width="23" height="23"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.7">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                </svg>

            </div>

        </div>

    </header>

    <!-- Menú lateral -->

    <dialog id="menu-lateral"
            class="av-sidebar"
            aria-labelledby="titulo-menu">

        <div class="av-sidebar-header">

            <h2 id="titulo-menu">AulaIA</h2>

            <button type="button"
                    id="cerrar-menu"
                    class="av-close-button"
                    aria-label="Cerrar menú">
                &times;
            </button>

        </div>

        <nav class="av-sidebar-nav" aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="panel_admin.php">Inicio</a>

            <span class="av-menu-label">ADMINISTRACIÓN</span>

            <a href="usuarios_admin.php"
               class="av-menu-active"
               aria-current="page">
                Usuarios
            </a>

            <a href="vinculos_apoderado.php">
                Vinculaciones
            </a>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- Contenido -->

    <main class="av-main">

        <div class="av-breadcrumb">
            <a href="panel_admin.php">Inicio</a>
            <span aria-hidden="true">/</span>
            <span>Usuarios</span>
        </div>

        <section class="av-welcome">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    ADMINISTRACIÓN
                </span>

                <h1>Gestión de usuarios</h1>

                <p>Administra las cuentas de tu establecimiento.</p>

            </div>

        </section>

        <section class="av-courses">

            <?php if ($mensaje !== ''): ?>

                <div class="admin-mensaje" role="status">
                    <?= escaparApoderados($mensaje) ?>
                </div>

            <?php endif; ?>

            <?php if ($error !== ''): ?>

                <div class="admin-mensaje admin-error" role="alert">
                    <?= escaparApoderados($error) ?>
                </div>

            <?php else: ?>

                <div class="admin-panel">

                    <h2>Usuarios registrados</h2>

                    <!-- Buscador y filtros -->

                    <div class="admin-filtros">

                        <input type="search"
                               id="buscar-usuario"
                               placeholder="Buscar usuario..."
                               aria-label="Buscar por nombre, RUT o usuario">

                        <select id="filtrar-rol"
                                aria-label="Filtrar por rol">

                            <option value="">Todos los roles</option>

                            <?php foreach ($roles as $valor => $etiqueta): ?>

                                <option value="<?= escaparApoderados($valor) ?>">
                                    <?= escaparApoderados($etiqueta) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <!-- Tabla de usuarios -->

                    <div class="admin-tabla-scroll"
                         tabindex="0"
                         role="region"
                         aria-label="Usuarios registrados">

                        <table class="admin-tabla">

                            <thead>

                                <tr>
                                    <th scope="col">Nombre</th>
                                    <th scope="col">Usuario</th>
                                    <th scope="col">RUT</th>
                                    <th scope="col">Rol</th>
                                    <th scope="col">Estado</th>
                                    <th scope="col">Acción</th>
                                </tr>

                            </thead>

                            <tbody id="lista-usuarios">

                                <?php foreach ($usuarios as $usuario): ?>

                                    <?php

                                    $nombreCompleto = trim(
                                        $usuario['nombre'] . ' ' .
                                        $usuario['apellido']
                                    );

                                    $activo = in_array(
                                        $usuario['activo'],
                                        [true, 1, '1', 't', 'true'],
                                        true
                                    );

                                    $protegido =
                                        $usuario['rol'] === 'admin' ||
                                        (int) $usuario['id'] === $adminId;

                                    ?>

                                    <tr data-rol="<?= escaparApoderados($usuario['rol']) ?>"
                                        data-usuario>

                                        <td class="admin-nombre">
                                            <?= escaparApoderados($nombreCompleto) ?>
                                        </td>

                                        <td>
                                            <?= escaparApoderados($usuario['username']) ?>
                                        </td>

                                        <td>
                                            <?= escaparApoderados($usuario['rut']) ?>
                                        </td>

                                        <td>
                                            <?= escaparApoderados(
                                                $roles[$usuario['rol']] ?? $usuario['rol']
                                            ) ?>
                                        </td>

                                        <td>

                                            <span class="<?= $activo
                                                ? 'admin-activo'
                                                : 'admin-inactivo' ?>">

                                                <?= $activo ? 'Activo' : 'Inactivo' ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php if ($protegido): ?>

                                                <span class="admin-protegido">
                                                    Protegido
                                                </span>

                                            <?php else: ?>

                                                <form method="POST"
                                                      class="admin-form-estado"
                                                      data-nombre="<?= escaparApoderados($nombreCompleto) ?>">

                                                    <input type="hidden"
                                                           name="csrf_token"
                                                           value="<?= escaparApoderados($_SESSION['csrf_admin_usuarios']) ?>">

                                                    <input type="hidden"
                                                           name="usuario_id"
                                                           value="<?= (int) $usuario['id'] ?>">

                                                    <input type="hidden"
                                                           name="accion"
                                                           value="<?= $activo ? 'desactivar' : 'activar' ?>">

                                                    <button type="submit"
                                                            class="admin-estado-btn <?= $activo
                                                                ? 'admin-desactivar'
                                                                : 'admin-activar' ?>">

                                                        <?= $activo
                                                            ? 'Desactivar'
                                                            : 'Activar' ?>

                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                <tr id="sin-usuarios"
                                    <?= $usuarios ? 'hidden' : '' ?>>

                                    <td colspan="6" class="admin-vacio">
                                        No se encontraron usuarios.
                                    </td>

                                </tr>

                            </tbody>

                        </table>

                    </div>

                </div>

            <?php endif; ?>

            <a class="admin-volver" href="panel_admin.php">
                &larr; Volver al inicio
            </a>

        </section>

    </main>

    <!-- Footer -->

    <footer class="av-footer">

        <div class="av-footer-central">

            <img src="img/Logo.png"
                 alt=""
                 class="av-footer-main-logo">

            <h2>AulaIA</h2>

            <p class="av-footer-description">
                Tu espacio para aprender, crecer y descubrir.
            </p>

        </div>

        <div class="av-footer-bottom">

            <div class="av-footer-project">

                <img src="img/Logo.png" alt="">

                <div>
                    <strong>AulaIA</strong>
                    <span>Plataforma educativa</span>
                </div>

            </div>

            <div class="av-footer-copyright">
                <span>&copy; <?= date('Y') ?> AulaIA.</span>
                <span>Todos los derechos reservados.</span>
            </div>

        </div>

    </footer>

</body>
</html>
