<?php

require_once __DIR__ . '/config/materiales.php';

exigirRol('alumno');
header('Cache-Control: private, no-store');

$error = false;
$cursos = [];
$historial = [];

try {
    require_once __DIR__ . '/config/conexion.php';

    // Asignaturas reales del estudiante.
    $stmt = $pdo->prepare("
        SELECT a.id, a.nombre, a.asignatura_nombre
        FROM aulas a
        JOIN aula_alumnos aa ON aa.aula_id = a.id
        JOIN usuarios u ON u.id = aa.alumno_id
    AND u.colegio_id = a.colegio_id
        WHERE u.id = :usuario_id
          AND u.colegio_id = :colegio_id
          AND u.rol = 'alumno'
          AND u.activo = TRUE
          AND a.activo = TRUE
        ORDER BY COALESCE(
            NULLIF(BTRIM(a.asignatura_nombre), ''),
            a.nombre
        ), a.id
    ");

    $stmt->execute([
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':colegio_id' => (int) $_SESSION['colegio_id']
    ]);

    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Últimos resultados calificados.
    if ($pdo->query("SELECT to_regclass('entregas')")->fetchColumn()) {
        $stmt = $pdo->prepare("
            SELECT
                e.id,
                v.titulo,
                COALESCE(an.calificacion, e.calificacion) AS calificacion,
                e.fecha_entrega,
                a.asignatura_nombre,
                a.nombre AS aula_nombre
            FROM entregas e
            JOIN evaluaciones v ON v.id = e.evaluacion_id
            LEFT JOIN libro_notas ln
    ON ln.evaluacion_id = v.id
    AND ln.aula_id = v.aula_id
    AND ln.tipo = 'automatica'

LEFT JOIN ajustes_notas an
    ON an.libro_nota_id = ln.id
    AND an.aula_id = v.aula_id
    AND an.estudiante_id = e.estudiante_id
            JOIN aulas a ON a.id = v.aula_id
            JOIN usuarios u ON u.id = e.estudiante_id
                AND u.colegio_id = a.colegio_id
            JOIN aula_alumnos aa ON aa.aula_id = a.id
                AND aa.alumno_id = u.id
            WHERE e.estudiante_id = ?
              AND a.colegio_id = ?
              AND u.activo
              AND u.rol = 'alumno'
              AND a.activo
              AND v.publicada_en IS NOT NULL
              AND e.estado = 'calificada'
            ORDER BY e.fecha_entrega DESC
            LIMIT 5
        ");

        $stmt->execute([
            (int) $_SESSION['usuario_id'],
            (int) $_SESSION['colegio_id']
        ]);

        $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Throwable $e) {
    error_log('Inicio estudiante: ' . $e->getMessage());
    http_response_code(500);
    $error = true;
}

$nombre = trim($_SESSION['nombre'] ?? '');
$primerNombre = explode(' ', $nombre)[0] ?: 'Estudiante';

function nombreAsignatura(array $curso): string
{
    $nombre = trim($curso['asignatura_nombre'] ?? '');
    return $nombre !== '' ? $nombre : $curso['nombre'];
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Inicio | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/estudiante.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
</head>

<body class="av-dashboard">

    <!-- HEADER ORIGINAL -->

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

            <a href="mis_cursos.php" class="av-logo">
                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>
            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">
                <strong><?= escaparMaterial($nombre) ?></strong>
                <span>Estudiante</span>
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

    <!-- MENÚ LATERAL DESPLEGABLE -->

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

            <a href="mis_cursos.php"
               class="av-menu-active"
               aria-current="page">
                Inicio
            </a>

            <span class="av-menu-label">MIS ASIGNATURAS</span>

            <?php if (!$error && $cursos): ?>

                <?php foreach ($cursos as $curso): ?>

                    <a href="asignatura.php?aula_id=<?= (int) $curso['id'] ?>">
                        <?= escaparMaterial(nombreAsignatura($curso)) ?>
                    </a>

                <?php endforeach; ?>

            <?php else: ?>

                <p>No tienes asignaturas inscritas.</p>

            <?php endif; ?>

            <span class="av-menu-label">HERRAMIENTAS</span>

            <a href="tutor.php">Tutor IA</a>

            <a href="mis_cursos.php#resultados">Mis resultados</a>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- CONTENIDO PRINCIPAL -->

    <main class="av-main">

        <div class="av-breadcrumb">
            <span>Inicio</span>
        </div>

        <?php if ($error): ?>

            <div class="av-notes-message av-notes-error" role="alert">
                No se pudo cargar tu información. Intenta nuevamente.
            </div>

        <?php else: ?>

            <!-- BIENVENIDA -->

            <section class="av-welcome">

                <div class="av-welcome-text">

                    <span class="av-eyebrow">
                        BIENVENIDO A AULAIA
                    </span>

                    <h1>
                        ¡Hola, <?= escaparMaterial($primerNombre) ?>!
                    </h1>

                    <p>
                        Revisa tus asignaturas y tus notas, y continúa avanzando
                        en tu aprendizaje.
                    </p>

                </div>

                <svg class="av-welcome-icon"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1"
                     aria-hidden="true">

                    <path d="M12 7v14M3 4h5a4 4 0 0 1 4 4
                             4 4 0 0 1 4-4h5v15h-5
                             a4 4 0 0 0-4 2
                             4 4 0 0 0-4-2H3z"/>

                </svg>

            </section>
            <!-- Mis asignaturas -->

            <section class="av-courses">

                <div class="av-section-heading">
                    <h2>Mis asignaturas</h2>
                </div>

                <?php if (!$cursos): ?>

                    <div class="av-asignaturas-vacio">
                        No tienes asignaturas inscritas.
                    </div>

                <?php else: ?>

                    <div class="av-asignaturas-lista">

                        <?php foreach ($cursos as $curso): ?>

                            <a class="av-asignatura-fila"
                            href="asignatura.php?aula_id=<?= (int) $curso['id'] ?>">

                                <span class="av-asignatura-linea"
                                    aria-hidden="true"></span>

                                <h3>
                                    <?= escaparMaterial(nombreAsignatura($curso)) ?>
                                </h3>

                                <span class="av-asignatura-acceso">
                                    Ingresar
                                    <span aria-hidden="true">&rarr;</span>
                                </span>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>
            <!-- Tutor IA -->

            <section class="av-courses">

                <div class="av-section-heading">
                    <h2>Tu Tutor IA</h2>
                </div>

                <article class="tutor-destacado">

                    <div class="tutor-contenido">

                        <h3>¿Necesitas ayuda con tus estudios?</h3>

                        <p>
                            Resuelve tus dudas y comprende mejor los contenidos
                            de tus asignaturas con ayuda de tu Tutor IA.
                        </p>

                        <a href="tutor.php" class="tutor-boton">
                            Conversar con mi tutor
                            <span aria-hidden="true">&rarr;</span>
                        </a>

                    </div>

                </article>

            </section>

<!-- Resultados -->

<section class="av-courses" id="resultados">

    <div class="av-section-heading">
        <h2>Mis notas</h2>
    </div>

    <?php if (!$historial): ?>

        <div class="av-empty">
            Todavía no tienes evaluaciones calificadas.
        </div>

    <?php else: ?>

        <div class="av-resultados-grid">

            <?php foreach ($historial as $resultado): ?>

                <?php
                    $nota = (float) $resultado['calificacion'];

                    $asignatura = trim(
                        $resultado['asignatura_nombre'] ?? ''
                    ) ?: $resultado['aula_nombre'];

                    $fecha = (
                        new DateTimeImmutable($resultado['fecha_entrega'])
                    )->setTimezone(
                        new DateTimeZone('America/Santiago')
                    )->format('d/m/Y');
                ?>

                <article class="av-resultado-card">

                    <div class="av-resultado-superior">

                        <span class="av-resultado-asignatura">
                            <?= escaparMaterial($asignatura) ?>
                        </span>

                    </div>

                    <h3>
                        <?= escaparMaterial($resultado['titulo']) ?>
                    </h3>

                    <div class="av-resultado-detalle">

                        <div class="av-resultado-nota">

                            <span>Nota obtenida</span>

                            <strong class="<?= $nota < 4.0
                                ? 'nota-reprobada'
                                : 'nota-aprobada' ?>">

                                <?= number_format($nota, 1, ',', '.') ?>

                            </strong>

                        </div>

                        <div class="av-resultado-fecha">

                            <span>Fecha de entrega</span>

                            <strong>
                                <?= escaparMaterial($fecha) ?>
                            </strong>

                        </div>

                    </div>

                    <a class="av-resultado-link"
                       href="evaluacion_resultado.php?id=<?= escaparMaterial($resultado['id']) ?>">

                        Ver detalle de la evaluación

                        <span aria-hidden="true">&rarr;</span>

                    </a>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</section>

        <?php endif; ?>

    </main>

    <!-- FOOTER ORIGINAL DE AULAIA -->

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
