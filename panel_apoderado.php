<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';

exigirRol('apoderado');
header('Cache-Control: private, no-store');

$error = false;
$accesoDenegado = false;
$pupilos = [];
$rendimiento = [];
$seleccionado = null;
$hijo = null;
$maxNotas = 3;

try {
    require __DIR__ . '/config/conexion.php';

    $pupilos = pupilosAutorizados(
        $pdo,
        (int) $_SESSION['usuario_id'],
        (int) $_SESSION['colegio_id']
    );

    // Seleccionar únicamente estudiantes vinculados.

    if (isset($_GET['estudiante_id'])) {

        $seleccionado = idApoderados(
            $_GET['estudiante_id']
        );

        foreach ($pupilos as $pupilo) {
            if ((int) $pupilo['id'] === $seleccionado) {
                $hijo = $pupilo;
                break;
            }
        }

        if ($hijo === null) {
            http_response_code(403);
            $accesoDenegado = true;
        } else {
            $rendimiento = rendimientoApoderado(
                $pdo,
                (int) $_SESSION['usuario_id'],
                (int) $_SESSION['colegio_id'],
                $seleccionado
            );

            foreach ($rendimiento as $aula) {
                $maxNotas = max(
                    $maxNotas,
                    count($aula['notas'])
                );
            }
        }
    }

} catch (Throwable $e) {
    error_log('Panel apoderado: ' . $e->getMessage());
    http_response_code(500);
    $error = true;
}

$nombre = trim($_SESSION['nombre'] ?? '');
$primerNombre = explode(' ', $nombre)[0] ?: 'Apoderado';

$nombreHijo = $hijo !== null
    ? trim($hijo['nombre'] . ' ' . $hijo['apellido'])
    : '';

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Apoderado | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/apoderado-panel.css">

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
                     fill="none" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>

            </button>

            <a href="panel_apoderado.php" class="av-logo">
                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>
            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">
                <strong><?= escaparApoderados($nombre) ?></strong>
                <span>Apoderado</span>
            </div>

            <div class="av-avatar" aria-hidden="true">

                <svg viewBox="0 0 24 24"
                     width="23" height="23"
                     fill="none" stroke="currentColor"
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

            <a href="panel_apoderado.php"
               <?= $hijo === null
                   ? 'class="av-menu-active" aria-current="page"'
                   : '' ?>>
                Inicio
            </a>

            <span class="av-menu-label">MIS HIJOS</span>

            <?php if (!$error): ?>

                <?php foreach ($pupilos as $pupilo): ?>

                    <a href="panel_apoderado.php?estudiante_id=<?= (int) $pupilo['id'] ?>"
                       <?= $hijo !== null && (int) $pupilo['id'] === $seleccionado
                           ? 'class="av-menu-active" aria-current="page"'
                           : '' ?>>

                        <?= escaparApoderados(
                            $pupilo['nombre'] . ' ' . $pupilo['apellido']
                        ) ?>

                    </a>

                <?php endforeach; ?>

            <?php endif; ?>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- Contenido -->

    <main class="av-main">

        <div class="av-breadcrumb">

            <?php if ($hijo !== null): ?>

                <a href="panel_apoderado.php">Inicio</a>
                <span aria-hidden="true">/</span>
                <span><?= escaparApoderados($nombreHijo) ?></span>

            <?php else: ?>

                <span>Inicio</span>

            <?php endif; ?>

        </div>

        <?php if ($accesoDenegado): ?>

            <div class="ap-aviso" role="alert">
                <p>Estudiante no disponible.</p>
                <a href="panel_apoderado.php">Volver al inicio</a>
            </div>

        <?php elseif ($error): ?>

            <div class="ap-aviso" role="alert">
                No se pudo cargar la información.
                Intenta nuevamente.
            </div>

        <?php elseif ($hijo === null): ?>

            <!-- Inicio del apoderado -->

            <section class="av-welcome">

                <div class="av-welcome-text">

                    <span class="av-eyebrow">
                        BIENVENIDO A AULAIA
                    </span>

                    <h1>
                        ¡Hola, <?= escaparApoderados($primerNombre) ?>!
                    </h1>

                    <p>Consulta las calificaciones de tus hijos.</p>

                </div>

            </section>

            <section class="av-courses">

                <div class="av-section-heading">
                    <h2>Mis hijos</h2>
                </div>

                <?php if (!$pupilos): ?>

                    <div class="ap-aviso">
                        No tienes estudiantes vinculados a tu cuenta.
                        Contacta a Coordinación.
                    </div>

                <?php else: ?>

                    <div class="ap-hijos-lista">

                        <?php foreach ($pupilos as $pupilo): ?>

                            <a class="ap-hijo"
                               href="panel_apoderado.php?estudiante_id=<?= (int) $pupilo['id'] ?>">

                                <div class="ap-hijo-icono">

                                    <svg viewBox="0 0 24 24"
                                         width="26" height="26"
                                         fill="none"
                                         stroke="currentColor"
                                         stroke-width="1.7"
                                         aria-hidden="true">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                                    </svg>

                                </div>

                                <div class="ap-hijo-info">

                                    <h3>
                                        <?= escaparApoderados(
                                            $pupilo['nombre'] . ' ' . $pupilo['apellido']
                                        ) ?>
                                    </h3>

                                    <span>Estudiante</span>

                                </div>

                                <span class="ap-hijo-acceso">
                                    Ver notas
                                    <span aria-hidden="true">&rarr;</span>
                                </span>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php else: ?>

            <!-- Calificaciones del estudiante -->

            <section class="av-welcome">

                <div class="av-welcome-text">

                    <span class="av-eyebrow">
                        RENDIMIENTO ACADÉMICO
                    </span>

                    <h1><?= escaparApoderados($nombreHijo) ?></h1>

                    <p>Consulta las calificaciones por asignatura.</p>

                </div>

            </section>

            <section class="av-courses">

                <div class="ap-notas-card">

                    <div class="ap-notas-header">

                        <h2>Calificaciones</h2>

                        <span>Solo lectura</span>

                    </div>

                    <?php if (!$rendimiento): ?>

                        <div class="ap-aviso">
                            Este estudiante todavía no tiene asignaturas.
                        </div>

                    <?php else: ?>

                        <div class="ap-tabla-scroll"
                             tabindex="0"
                             role="region"
                             aria-label="Tabla de calificaciones">

                            <table class="ap-tabla">

                                <thead>

                                    <tr>

                                        <th scope="col">Asignatura</th>

                                        <?php for ($i = 1; $i <= $maxNotas; $i++): ?>

                                            <th scope="col">
                                                Nota <?= $i ?>
                                            </th>

                                        <?php endfor; ?>

                                        <th scope="col">Promedio</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($rendimiento as $aula): ?>

                                        <tr>

                                            <th scope="row">
                                                <?= escaparApoderados($aula['asignatura']) ?>
                                            </th>

                                            <?php for ($i = 0; $i < $maxNotas; $i++): ?>

                                                <?php
                                                $nota = $aula['notas'][$i]['calificacion'] ?? null;
                                                ?>

                                                <td class="<?= $nota === null
                                                    ? 'ap-nota-vacia'
                                                    : ($nota < 4.0 ? 'ap-nota-baja' : '') ?>">

                                                    <?= $nota === null
                                                        ? '&mdash;'
                                                        : number_format($nota, 1, ',', '.') ?>

                                                </td>

                                            <?php endfor; ?>

                                            <?php $promedio = $aula['promedio']; ?>

                                            <td class="ap-promedio <?= $promedio !== null && $promedio < 4.0
                                                ? 'ap-nota-baja'
                                                : '' ?>">

                                                <?= $promedio === null
                                                    ? '&mdash;'
                                                    : number_format($promedio, 1, ',', '.') ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php endif; ?>

                </div>

                <a class="ap-volver" href="panel_apoderado.php">
                    <span aria-hidden="true">&larr;</span>
                    Volver a mis hijos
                </a>

            </section>

        <?php endif; ?>

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
