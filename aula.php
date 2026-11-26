<?php

require_once __DIR__ . '/config/rol.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/config/conexion.php';
}

/* SOLO PROFESORES */

exigirRol('profesor');

/* VALIDAR ID DEL AULA */

$aulaId = PHP_SAPI === 'cli'
    ? filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT)
    : filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$aulaId || $aulaId < 1) {
    http_response_code(400);
    exit('Aula inválida.');
}

$profesorId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

try {

    /* COMPROBAR QUE EL PROFESOR PERTENECE AL AULA */

    $stmt = $pdo->prepare(
        'SELECT a.id, a.nombre
         FROM aulas AS a
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         WHERE a.id = :aula_id
           AND a.colegio_id = :colegio_id
           AND ap.profesor_id = :profesor_id
           AND a.activo = TRUE
         LIMIT 1'
    );

    $stmt->execute([
        ':aula_id' => $aulaId,
        ':colegio_id' => $colegioId,
        ':profesor_id' => $profesorId
    ]);

    $aula = $stmt->fetch();

    if (!$aula) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta aula.');
    }

    /* OBTENER ALUMNOS DEL AULA */

    $stmt = $pdo->prepare(
        'SELECT u.id, u.rut, u.username, u.nombre, u.apellido
         FROM usuarios AS u
         INNER JOIN aula_alumnos AS aa
             ON aa.alumno_id = u.id
         WHERE aa.aula_id = :aula_id
           AND u.colegio_id = :colegio_id
           AND u.rol = :rol
           AND u.activo = TRUE
         ORDER BY u.apellido ASC, u.nombre ASC'
    );

    $stmt->execute([
        ':aula_id' => $aulaId,
        ':colegio_id' => $colegioId,
        ':rol' => 'alumno'
    ]);

    $alumnos = $stmt->fetchAll();

} catch (PDOException $e) {

    http_response_code(500);
    exit('No se pudo cargar el aula.');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($aula['nombre'], ENT_QUOTES, 'UTF-8') ?> | AulaIA
    </title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/docente.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
</head>

<body class="av-dashboard">

    <!-- HEADER -->

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

            <a href="mis_aulas.php" class="av-logo">

                <img src="img/Logo.png" alt="">

                <span>AulaIA</span>

            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">

                <strong>
                    <?= htmlspecialchars(
                        $_SESSION['nombre'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </strong>

                <span>Profesor</span>

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

    <!-- MENÚ LATERAL -->

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

        <nav class="av-sidebar-nav"
             aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="mis_aulas.php">Inicio</a>

            <span class="av-menu-label">AULA ACTUAL</span>

            <a href="aula.php?id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               class="av-menu-active"
               aria-current="page">

                <?= htmlspecialchars(
                    $aula['nombre'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

            </a>

            <a href="materiales.php?aula_id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                Materiales
            </a>

            <a href="notas.php?aula_id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                Notas
            </a>

            <a href="#alumnos-aula" id="menu-alumnos">
                Estudiantes
            </a>

        </nav>

        <div class="av-sidebar-bottom">

            <a href="logout.php">Cerrar sesión</a>

        </div>

    </dialog>

    <!-- CONTENIDO PRINCIPAL -->

    <main class="av-main">

        <!-- RUTA DE NAVEGACIÓN -->

        <div class="av-breadcrumb">

            <a href="mis_aulas.php">Inicio</a>

            <span>/</span>

            <span>
                <?= htmlspecialchars(
                    $aula['nombre'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>

        </div>

        <!-- BANNER DEL AULA -->

        <section class="av-welcome av-class-banner">

            <div class="av-welcome-text">

                <span class="av-eyebrow">AULA VIRTUAL</span>

                <h1>
                    <?= htmlspecialchars(
                        $aula['nombre'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </h1>

                <p>
                    Administra los recursos educativos y estudiantes
                    de esta aula.
                </p>

                <div class="av-class-count">

                    <svg viewBox="0 0 24 24"
                         width="20" height="20"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="1.7"
                         aria-hidden="true">

                        <circle cx="9" cy="7" r="3"/>
                        <path d="M2 21v-3a7 7 0 0 1 14 0v3"/>
                        <path d="M17 4a3 3 0 0 1 0 6M22 21v-3a6 6 0 0 0-4-6"/>

                    </svg>

                    <?= count($alumnos) ?>

                    <?= count($alumnos) === 1
                        ? 'estudiante inscrito'
                        : 'estudiantes inscritos' ?>

                </div>

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

       <!-- RECURSOS DEL AULA -->

<section class="av-courses"
         aria-labelledby="recursos-aula">

    <div class="av-section-heading">

        <div>
            <h2 id="recursos-aula">Recursos del aula</h2>

        </div>

    </div>

    <div class="av-resource-grid">

        <!-- MATERIALES -->

        <article class="av-resource-card">

            <h3>Materiales</h3>

            <p>
                Sube, consulta y organiza los documentos
                educativos de tus estudiantes.
            </p>

            <a class="av-course-button"
               href="materiales.php?aula_id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                Ver materiales

            </a>

        </article>

        <!-- EVALUACIONES -->

        <article class="av-resource-card">

            <h3>Evaluaciones</h3>

            <p>
                Crea y administra las evaluaciones
                de tus estudiantes.
            </p>

            <a class="av-course-button"
               href="evaluaciones.php?aula_id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                Ver evaluaciones

            </a>

        </article>

        <!-- NOTAS -->

        <article class="av-resource-card">

            <h3>Notas</h3>

            <p>
                Consulta las calificaciones y el rendimiento
                académico de tus estudiantes.
            </p>

            <a class="av-course-button"
               href="notas.php?aula_id=<?= htmlspecialchars((string) (int) $aulaId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

                Ver notas

            </a>

        </article>

    </div>

</section>

        <!-- ESTUDIANTES -->

        <section class="av-courses"
                 aria-labelledby="alumnos-aula">

            <div class="av-section-heading">

                <div>

                    <h2 id="alumnos-aula">Estudiantes inscritos</h2>

                </div>

                <span class="av-course-count">

                    Total: <?= count($alumnos) ?>

                </span>

            </div>

            <?php if (!$alumnos): ?>

                <div class="av-empty">

                    No hay estudiantes asignados a esta aula.

                </div>

            <?php else: ?>

                <ul class="av-student-list">

                    <?php foreach ($alumnos as $alumno): ?>

                        <li class="av-student-row">

                            <div class="av-student-avatar">

                                <svg viewBox="0 0 24 24"
                                     width="24" height="24"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="1.7"
                                     aria-hidden="true">

                                    <circle cx="12" cy="8" r="4"/>
                                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>

                                </svg>

                            </div>

                            <div class="av-student-info">

                                <h3>
                                    <?= htmlspecialchars(
                                        $alumno['nombre'] . ' ' . $alumno['apellido'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </h3>

                                <span>
                                    <?= htmlspecialchars(
                                        $alumno['username'] ?? '',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                            </div>

                            <span class="av-student-status">

                                Inscrito

                            </span>

                        </li>

                    <?php endforeach; ?>

                </ul>

            <?php endif; ?>

        </section>

    </main>

    <!-- FOOTER -->

    <?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>