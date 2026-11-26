<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';

exigirRol('admin');
header('Cache-Control: private, no-store');

try {
    require __DIR__ . '/config/conexion.php';

    $admin = consultaApoderados($pdo, "
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND colegio_id = ?
          AND rol = 'admin'
          AND activo = TRUE
    ", [
        (int) $_SESSION['usuario_id'],
        (int) $_SESSION['colegio_id']
    ])->fetchColumn();

    if ($admin === false) {
        http_response_code(403);
        exit('No tienes permiso para acceder.');
    }

} catch (Throwable $e) {
    error_log('Panel administrador: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo cargar el panel.');
}

$nombre = trim($_SESSION['nombre'] ?? '');
$primerNombre = explode(' ', $nombre)[0] ?: 'Administrador';

$secciones = [
    [
        'titulo' => 'Usuarios',
        'descripcion' => 'Gestiona profesores, estudiantes y apoderados.',
        'url' => 'usuarios_admin.php'
    ],
    [
        'titulo' => 'Aulas',
        'descripcion' => 'Administra cursos, asignaturas y sus integrantes.',
        'url' => 'aulas_admin.php'
    ],
    [
        'titulo' => 'Registro de usuarios',
        'descripcion' => 'Incorpora usuarios de forma individual o masiva.',
        'url' => 'registro_usuarios.php'
    ]
];

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Administración | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/admin.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>

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

            <a href="panel_admin.php"
               class="av-menu-active"
               aria-current="page">
                Inicio
            </a>

            <span class="av-menu-label">ADMINISTRACIÓN</span>

            <?php foreach ($secciones as $seccion): ?>

                <?php if ($seccion['url'] !== null): ?>

                    <a href="<?= escaparApoderados($seccion['url']) ?>">
                        <?= escaparApoderados($seccion['titulo']) ?>
                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- Contenido principal -->

    <main class="av-main">

        <div class="av-breadcrumb">
            <span>Inicio</span>
        </div>

        <section class="av-welcome">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    PANEL DE ADMINISTRACIÓN
                </span>

                <h1>
                    ¡Hola, <?= escaparApoderados($primerNombre) ?>!
                </h1>

                <p>
                    Gestiona los usuarios y la organización
                    de tu establecimiento.
                </p>

            </div>

        </section>

        <section class="av-courses">

            <div class="av-section-heading">
                <h2>Administración</h2>
            </div>

            <div class="admin-grid admin-grid-inicio">

                <?php foreach ($secciones as $seccion): ?>

                    <article class="admin-card">

                        <h3>
                            <?= escaparApoderados($seccion['titulo']) ?>
                        </h3>

                        <p>
                            <?= escaparApoderados($seccion['descripcion']) ?>
                        </p>

                        <?php if ($seccion['url'] !== null): ?>

                            <a class="admin-acceso"
                               href="<?= escaparApoderados($seccion['url']) ?>">

                                Ingresar
                                <span aria-hidden="true">&rarr;</span>

                            </a>

                        <?php else: ?>

                            <span class="admin-pendiente">
                                Próximamente
                            </span>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </div>

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
