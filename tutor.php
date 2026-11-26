<?php

declare(strict_types=1);

require_once __DIR__ . '/config/materiales.php';

exigirRol('alumno');

header('Cache-Control: private, no-store');

$error = false;
$aulas = [];

try {
    require_once __DIR__ . '/config/conexion.php';

    $stmt = $pdo->prepare(
        consultaAulasMateriales(false) . ' ORDER BY a.nombre, a.id'
    );

    $stmt->execute(parametrosAulasMateriales());

    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($aulas as &$aula) {
        $aula['asignatura'] = trim(
            $aula['asignatura_nombre'] ?? ''
        ) ?: $aula['nombre'];
    }

    unset($aula);

} catch (RuntimeException $e) {
    http_response_code(500);
    $error = true;
    $aulas = [];
}

$primeraAula = $aulas[0] ?? null;

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Tutor IA | AulaIA</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/tutor.css">

<script src="js/dashboard.js" defer></script>
<script src="js/tutor.js" defer></script>

</head>

<body class="tutor-page">

    <!-- Encabezado -->

    <header class="tutor-header">

        <div class="tutor-header-left">

<button type="button"
        id="abrir-menu"
        class="tutor-menu"
        aria-label="Abrir menú general"
        aria-controls="menu-lateral"
        aria-expanded="false">

                <svg viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.8"
                     aria-hidden="true">

                    <path d="M4 6h16M4 12h16M4 18h16"/>

                </svg>

            </button>

            <a href="mis_cursos.php" class="tutor-logo">

                <img src="img/Logo.png" alt="">

                <span>AulaIA</span>

            </a>

        </div>

        <div class="tutor-perfil">

            <div class="tutor-perfil-texto">

                <strong>
                    <?= escaparMaterial($_SESSION['nombre'] ?? '') ?>
                </strong>

                <span>Estudiante</span>

            </div>

            <div class="tutor-avatar">

                <svg viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.6"
                     aria-hidden="true">

                    <circle cx="12" cy="8" r="4"/>

                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>

                </svg>

            </div>

        </div>

    </header>
<!-- Menú general del estudiante -->

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

        <a href="mis_cursos.php">Inicio</a>

        <span class="av-menu-label">
            MIS ASIGNATURAS
        </span>

        <?php if (!$error && $aulas): ?>

            <?php foreach ($aulas as $aula): ?>

                <a href="asignatura.php?aula_id=<?= (int) $aula['id'] ?>">

                    <?= escaparMaterial($aula['asignatura']) ?>

                </a>

            <?php endforeach; ?>

        <?php else: ?>

            <p>No tienes asignaturas disponibles.</p>

        <?php endif; ?>

        <span class="av-menu-label">
            HERRAMIENTAS
        </span>

        <a href="tutor.php"
           class="av-menu-active"
           aria-current="page">
            Tutor IA
        </a>

        <a href="mis_cursos.php#resultados">
            Mis resultados
        </a>

    </nav>

    <div class="av-sidebar-bottom">

        <a href="logout.php">
            Cerrar sesión
        </a>

    </div>

</dialog>
    <div class="tutor-layout" id="tutor-layout">

        <!-- Menú lateral -->

        <aside class="tutor-sidebar" id="tutor-sidebar">

            <h2>Tutor IA</h2>

            <?php if ($primeraAula): ?>

                <button type="button"
                        class="tutor-nueva"
                        id="tutor-nueva">

                    <span aria-hidden="true">+</span>

                    Nueva consulta

                </button>

                <div class="tutor-sidebar-label">
                    Mis asignaturas
                </div>

                <nav class="tutor-asignaturas"
                     aria-label="Asignaturas">

                    <?php foreach ($aulas as $indice => $aula): ?>

                        <button type="button"
                                class="tutor-asignatura <?= $indice === 0 ? 'activa' : '' ?>"
                                data-aula="<?= (int) $aula['id'] ?>"
                                data-nombre="<?= escaparMaterial($aula['asignatura']) ?>"
                                aria-pressed="<?= $indice === 0 ? 'true' : 'false' ?>">

                            <?= escaparMaterial($aula['asignatura']) ?>

                        </button>

                    <?php endforeach; ?>

                </nav>

            <?php endif; ?>

        </aside>

        <!-- Área de conversación -->

        <main class="tutor-main">

            <?php if ($error): ?>

                <div class="tutor-aviso" role="alert">
                    No se pudieron cargar tus asignaturas.
                    Intenta nuevamente.
                </div>

            <?php elseif (!$primeraAula): ?>

                <div class="tutor-aviso">
                    Debes estar matriculado en un aula
                    para utilizar el Tutor IA.
                </div>

            <?php else: ?>

                <div class="tutor-chat" id="tutor-chat">

                    <!-- Bienvenida -->

                    <section class="tutor-bienvenida"
                             id="tutor-bienvenida">

                        <img src="img/Logo.png"
                             alt=""
                             class="tutor-bienvenida-logo">

                        <h1>¿En qué puedo ayudarte?</h1>

                        <p>
                            Resuelve tus dudas y aprende
                            con tu Tutor IA.
                        </p>

                    </section>

                    <!-- Conversación -->

                    <section id="tutor-historial"
                             class="tutor-historial"
                             aria-label="Conversación"
                             hidden>

                        <ol id="tutor-respuestas"
                            class="tutor-respuestas"
                            role="list">
                        </ol>

                    </section>

                </div>

                <!-- Campo de consulta -->

                <div class="tutor-compositor">

                    <form id="tutor-form"
                          data-state="IDLE"
                          method="post"
                          action="tutor_ask.php">

                        <input type="hidden"
                               name="aula_id"
                               value="<?= (int) $primeraAula['id'] ?>">

                        <div class="tutor-caja">

                            <textarea id="tutor-pregunta"
                                      name="pregunta"
                                      aria-label="Pregunta para el Tutor IA"
                                      placeholder="Escribe tu pregunta..."
                                      maxlength="1500"
                                      rows="2"
                                      autocomplete="off"
                                      required></textarea>

                            <div class="tutor-caja-inferior">

                                <span id="tutor-aula-actual"
                                      class="tutor-aula-actual">

                                    <?= escaparMaterial($primeraAula['asignatura']) ?>

                                </span>

                                <button type="submit"
                                        id="tutor-enviar"
                                        aria-label="Enviar pregunta"
                                        disabled>

                                    <svg viewBox="0 0 24 24"
                                         fill="none"
                                         stroke="currentColor"
                                         stroke-width="2"
                                         stroke-linecap="round"
                                         stroke-linejoin="round"
                                         aria-hidden="true">

                                        <path d="M12 19V5M5 12l7-7 7 7"/>

                                    </svg>

                                </button>

                            </div>

                        </div>

                        <p id="tutor-estado"
                           class="tutor-estado"
                           role="status"
                           aria-live="polite"
                           aria-atomic="true"></p>

                        <noscript>
                            <p>Activa JavaScript para utilizar el Tutor IA.</p>
                        </noscript>

                    </form>

                    <p class="tutor-aclaracion">
                        El Tutor IA responde basándose en tus materiales de estudio.
                    </p>

                </div>

            <?php endif; ?>

        </main>

    </div>

</body>

</html>