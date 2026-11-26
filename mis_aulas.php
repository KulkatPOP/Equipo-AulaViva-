<?php

require_once __DIR__ . '/config/rol.php';

// Permite utilizar una base de datos aislada en las pruebas.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/config/conexion.php';
}

/* SOLO PROFESORES */

exigirRol('profesor');

/* OBTENER DATOS DE LA SESIÓN */

$profesorId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

try {

    /* BUSCAR SOLO LAS AULAS ASIGNADAS AL PROFESOR Y A SU COLEGIO */

    $stmt = $pdo->prepare(
        'SELECT a.id, a.nombre
         FROM aulas AS a
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         WHERE ap.profesor_id = :profesor_id
           AND a.colegio_id = :colegio_id
           AND a.activo = TRUE
         ORDER BY a.nombre ASC'
    );

    $stmt->execute([
        ':profesor_id' => $profesorId,
        ':colegio_id' => $colegioId
    ]);

    $aulas = $stmt->fetchAll();

} catch (PDOException $e) {

    http_response_code(500);
    exit('No se pudieron cargar las aulas.');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Inicio docente | AulaIA</title>

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
                     width="24"
                     height="24"
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
                     width="23"
                     height="23"
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

            <a href="mis_aulas.php"
               class="av-menu-active"
               aria-current="page">

                Inicio

            </a>

            <span class="av-menu-label">MIS AULAS</span>

            <?php if (!$aulas): ?>

                <p>No tienes aulas asignadas.</p>

            <?php else: ?>

                <?php foreach ($aulas as $aula): ?>

                    <a href="aula.php?id=<?= (int) $aula['id'] ?>">

                        <?= htmlspecialchars(
                            $aula['nombre'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </a>

                <?php endforeach; ?>

            <?php endif; ?>

        </nav>

        <div class="av-sidebar-bottom">

            <a href="logout.php">Cerrar sesión</a>

        </div>

    </dialog>

    <!-- CONTENIDO PRINCIPAL -->

    <main class="av-main">

        <!-- BIENVENIDA -->

        <section class="av-welcome">

            <div class="av-welcome-text">

                <span class="av-eyebrow">PANEL DOCENTE</span>

                <h1>
                    ¡Bienvenido, <?= htmlspecialchars(
                        $_SESSION['nombre'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>!
                </h1>

                <p>
                    Gestiona tus aulas, organiza tus materiales y
                    acompaña el aprendizaje de tus estudiantes.
                </p>

                <a href="#mis-aulas" class="av-welcome-button">

                    Explorar mis aulas &rarr;

                </a>

            </div>

            <svg class="av-welcome-icon"
                 viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1"
                 aria-hidden="true">

                <path d="M2 10l10-6 10 6-10 6-10-6z"/>
                <path d="M6 13v5c4 3 8 3 12 0v-5M22 10v7"/>

            </svg>

        </section>

        <!-- MIS AULAS -->

        <section id="mis-aulas" class="av-courses">

            <div class="av-section-heading">

                <div>

                    <h2>Mis aulas</h2>

                    <p>Selecciona un aula para comenzar.</p>

                </div>

                <span class="av-course-count">

                    <?= count($aulas) ?>

                    <?= count($aulas) === 1
                        ? 'aula asignada'
                        : 'aulas asignadas' ?>

                </span>

            </div>

            <?php if (!$aulas): ?>

                <div class="av-empty">

                    No tienes aulas asignadas.

                </div>

            <?php else: ?>

                <div class="av-course-grid">

                    <?php foreach ($aulas as $aula): ?>

                        <article class="av-course-card">

                            <div class="av-course-cover">

                                <svg viewBox="0 0 24 24"
                                     width="42"
                                     height="42"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="1.5"
                                     aria-hidden="true">

                                    <path d="M12 7v14M3 4h5a4 4 0 0 1 4 4
                                             4 4 0 0 1 4-4h5v15h-5
                                             a4 4 0 0 0-4 2
                                             4 4 0 0 0-4-2H3z"/>

                                </svg>

                                <span>Aula virtual</span>

                            </div>

                            <div class="av-course-body">

                                <h3>

                                    <?= htmlspecialchars(
                                        $aula['nombre'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </h3>

                                <p>
                                    Administra tus materiales,
                                    evaluaciones y estudiantes.
                                </p>

                                <a href="aula.php?id=<?= (int) $aula['id'] ?>"
                                   class="av-course-button">

                                    Ingresar al aula

                                    <span aria-hidden="true">
                                        &rarr;
                                    </span>

                                </a>

                            </div>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </section>

    </main>

    <!-- FOOTER -->

    <?php if (is_file(__DIR__ . '/includes/footer.php')): ?>

        <?php require __DIR__ . '/includes/footer.php'; ?>

    <?php else: ?>

        <footer class="av-footer">

            <span>&copy; <?= date('Y') ?> AulaIA.</span>

            <span>Plataforma educativa</span>

        </footer>

    <?php endif; ?>

</body>
</html>