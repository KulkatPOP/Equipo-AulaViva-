<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/evaluaciones_eliminar.php';

header('Cache-Control: private, no-store');

// Parámetros de acceso

$metodo = $_SERVER['REQUEST_METHOD'];

if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$aulaId = filter_input(
    INPUT_GET,
    'aula_id',
    FILTER_VALIDATE_INT,
    ['options' => [
        'min_range' => 1,
        'max_range' => 2147483647
    ]]
);

$id = $_GET['id'] ?? null;

if (!$aulaId || ($id !== null && !idEvaluacion($id))) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

$esProfesor = $_SESSION['rol'] === 'profesor';

// El estudiante consulta las evaluaciones desde su asignatura.

if (!$esProfesor && $id === null && $metodo === 'GET') {
    header(
        'Location: asignatura.php?aula_id='
        . $aulaId
        . '&seccion=evaluaciones',
        true,
        302
    );
    exit;
}

$inicio = $esProfesor
    ? 'mis_aulas.php'
    : 'mis_cursos.php';

$volver = $esProfesor
    ? 'aula.php?id=' . $aulaId
    : 'asignatura.php?aula_id=' . $aulaId;

$base = 'evaluaciones.php?aula_id=' . $aulaId;
$urlListado = $esProfesor
    ? $base
    : $volver . '&seccion=evaluaciones';
$editor = 'evaluacion_editar.php?aula_id=' . $aulaId;
$rendir = 'evaluacion_rendir.php?aula_id=' . $aulaId;

// Seguridad de eliminación

if ($esProfesor) {
    $_SESSION['csrf_eliminar_evaluacion'] ??= bin2hex(
        random_bytes(32)
    );
}

// Consultar aula

try {

    require __DIR__ . '/config/conexion.php';

    $aula = aulaEvaluaciones($pdo, $aulaId);

    if (!$aula) {
        http_response_code(403);
        exit('No tienes acceso a las evaluaciones de esta aula.');
    }
    // Nombre visible según el rol

$nombreAula = $esProfesor
    ? $aula['nombre']
    : (trim($aula['asignatura_nombre'] ?? '') ?: $aula['nombre']);

    // Eliminar evaluación

    if ($metodo === 'POST') {

        exigirRol('profesor');

        $token = $_POST['csrf_token'] ?? null;
        $accion = $_POST['accion'] ?? null;
        $evaluacionId = $_POST['evaluacion_id'] ?? null;

        if (
            !is_string($token)
            || !hash_equals(
                $_SESSION['csrf_eliminar_evaluacion'],
                $token
            )
        ) {
            http_response_code(403);
            exit('El formulario no es válido.');
        }

        if (
            $accion !== 'eliminar'
            || !idEvaluacion($evaluacionId)
        ) {
            http_response_code(400);
            exit('Solicitud inválida.');
        }

        try {

            eliminarEvaluacion(
                $pdo,
                $aulaId,
                $evaluacionId
            );

            $_SESSION['mensaje_evaluaciones'] = [
                'aula' => $aulaId,
                'tipo' => 'success',
                'texto' => 'Evaluación eliminada correctamente.'
            ];

        } catch (DomainException $e) {

            $_SESSION['mensaje_evaluaciones'] = [
                'aula' => $aulaId,
                'tipo' => 'error',
                'texto' => $e->getMessage()
            ];

        } catch (Throwable $e) {

            error_log(
                'Error al eliminar evaluación: '
                . $e->getMessage()
            );

            $_SESSION['mensaje_evaluaciones'] = [
                'aula' => $aulaId,
                'tipo' => 'error',
                'texto' => 'No se pudo eliminar la evaluación.'
            ];
        }

        header('Location: ' . $base, true, 303);
        exit;
    }

    // Consultar evaluaciones

    $proteccion = $esProfesor
        ? ",
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM entregas en
                    WHERE en.evaluacion_id = e.id
                )
                OR EXISTS (
                    SELECT 1
                    FROM libro_notas ln
                    WHERE ln.evaluacion_id = e.id
                    AND (
                        EXISTS (
                            SELECT 1
                            FROM ajustes_notas an
                            WHERE an.libro_nota_id = ln.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM notas_manuales nm
                            WHERE nm.libro_nota_id = ln.id
                        )
                    )
                )
                THEN 1
                ELSE 0
            END AS protegida"
        : '';

    $sql = "
        SELECT
            e.id,
            e.titulo,
            e.publicada_en,
            e.disponible_desde,
            e.disponible_hasta,
            CASE
                WHEN e.publicada_en IS NULL THEN 'borrador'
                WHEN e.disponible_desde > CURRENT_TIMESTAMP
                    THEN 'programada'
                WHEN e.disponible_hasta <= CURRENT_TIMESTAMP
                    THEN 'finalizada'
                ELSE 'disponible'
            END AS estado_disponibilidad
            $proteccion
        FROM evaluaciones e
        WHERE e.aula_id = :aula
    ";

    if (!$esProfesor) {
        $sql .= ' AND e.publicada_en IS NOT NULL';
    }

    $stmt = $pdo->prepare(
        $sql . ' ORDER BY e.publicada_en DESC NULLS FIRST, e.id'
    );

    $stmt->execute(['aula' => $aulaId]);

    $lista = $stmt->fetchAll();

    // Evaluación seleccionada

    $seleccion = null;

    if ($id !== null) {

        foreach ($lista as $evaluacion) {

            if ($evaluacion['id'] === $id) {
                $seleccion = $evaluacion;
                break;
            }
        }

        if (!$seleccion) {
            http_response_code(404);
            exit('Evaluación no disponible.');
        }
    }

    // Preguntas visibles únicamente para el profesor

    $preguntas = $seleccion && $esProfesor
        ? preguntasEvaluacion($pdo, $id, true)
        : [];

    // Intentos del estudiante

    $entregas = [];

    if (!$esProfesor) {

        $stmt = $pdo->prepare("
            SELECT
                en.id,
                en.evaluacion_id,
                en.estado
            FROM entregas en
            JOIN evaluaciones ev
                ON ev.id = en.evaluacion_id
            WHERE ev.aula_id = ?
                AND en.estudiante_id = ?
                AND ev.publicada_en IS NOT NULL
        ");

        $stmt->execute([
            $aulaId,
            (int) $_SESSION['usuario_id']
        ]);

        foreach ($stmt->fetchAll() as $entrega) {
            $entregas[$entrega['evaluacion_id']] = $entrega;
        }
    }

} catch (RuntimeException $e) {

    http_response_code(500);
    exit('No se pudieron consultar las evaluaciones.');
}

// Mensajes de confirmación

$mensaje = $_SESSION['mensaje_evaluaciones'] ?? null;

if ($mensaje && $mensaje['aula'] === $aulaId) {
    unset($_SESSION['mensaje_evaluaciones']);
} else {
    $mensaje = null;
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Evaluaciones | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/docente.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>

    <?php if ($esProfesor): ?>
        <script src="js/evaluaciones.js" defer></script>
    <?php endif; ?>

</head>

<body class="av-dashboard">

    <!-- Header -->

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

            <a href="<?= escaparMaterial($inicio) ?>"
               class="av-logo">

                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>

            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">

                <strong>
                    <?= escaparMaterial($_SESSION['nombre'] ?? '') ?>
                </strong>

                <span>
                    <?= $esProfesor ? 'Profesor' : 'Estudiante' ?>
                </span>

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

        <nav class="av-sidebar-nav"
             aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="<?= escaparMaterial($inicio) ?>">
                Inicio
            </a>

            <span class="av-menu-label">AULA ACTUAL</span>

            <a href="<?= escaparMaterial($volver) ?>">
                <?= escaparMaterial($nombreAula) ?>
            </a>

            <a href="materiales.php?aula_id=<?= $aulaId ?>">
                Materiales
            </a>

            <a href="<?= escaparMaterial($base) ?>"
               class="av-menu-active"
               aria-current="page">

                Evaluaciones

            </a>

            <?php if ($esProfesor): ?>

                <a href="notas.php?aula_id=<?= $aulaId ?>">
                    Notas
                </a>

                <a href="aula.php?id=<?= $aulaId ?>#alumnos-aula">
                    Estudiantes
                </a>

            <?php endif; ?>

        </nav>

        <div class="av-sidebar-bottom">

            <a href="logout.php">Cerrar sesión</a>

        </div>

    </dialog>

    <main class="av-main">

        <!-- Navegación -->

        <div class="av-breadcrumb">

            <a href="<?= escaparMaterial($inicio) ?>">
                Inicio
            </a>

            <span>/</span>

            <a href="<?= escaparMaterial($volver) ?>">
                <?= escaparMaterial($nombreAula) ?>
            </a>

            <span>/</span>

            <?php if ($seleccion): ?>

                <a href="<?= escaparMaterial($base) ?>">
                    Evaluaciones
                </a>

                <span>/</span>
                <span>Detalle</span>

            <?php else: ?>

                <span>Evaluaciones</span>

            <?php endif; ?>

        </div>

        <!-- Banner -->

        <section class="av-welcome av-class-banner">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    <?= $esProfesor ? 'GESTIÓN ACADÉMICA' : 'EVALUACIÓN' ?>
                </span>

                <h1>
                    <?= escaparMaterial(
                        $seleccion
                            ? ($seleccion['titulo'] ?: 'Borrador sin título')
                            : 'Evaluaciones del aula'
                    ) ?>
                </h1>

                <p>

                    <?php if ($seleccion): ?>

                        <?= escaparMaterial($nombreAula) ?>

                    <?php elseif ($esProfesor): ?>

                        Crea y administra las evaluaciones de los
                        estudiantes de <?= escaparMaterial($nombreAula) ?>

                    <?php else: ?>

                        Consulta las evaluaciones y sus fechas
                        de disponibilidad.

                    <?php endif; ?>

                </p>

                <a href="<?= escaparMaterial(
                    $seleccion ? $urlListado : $volver
                ) ?>"
                   class="av-welcome-button">

                    <?= $seleccion
                        ? 'Volver a evaluaciones'
                        : ($esProfesor
                            ? 'Volver al aula'
                            : 'Volver a mis cursos') ?>

                </a>

            </div>

        </section>

        <!-- Mensajes -->

        <?php if ($mensaje): ?>

            <p class="av-notes-message <?= $mensaje['tipo'] === 'success'
                ? 'av-notes-success'
                : 'av-notes-error' ?>"
               role="<?= $mensaje['tipo'] === 'success'
                ? 'status'
                : 'alert' ?>">

                <?= escaparMaterial($mensaje['texto']) ?>

            </p>

        <?php endif; ?>

        <?php if (!$seleccion): ?>

            <!-- Evaluaciones -->

            <section class="av-courses"
                     aria-labelledby="titulo-evaluaciones">

                <div class="av-section-heading">

                    <h2 id="titulo-evaluaciones">
                        Evaluaciones
                    </h2>

                    <?php if ($esProfesor): ?>

                        <a href="<?= escaparMaterial($editor) ?>"
                           class="av-notes-button">

                            + Crear evaluación

                        </a>

                    <?php endif; ?>

                </div>

                <?php if (!$lista): ?>

                    <div class="av-empty">

                        No hay evaluaciones disponibles para esta aula.

                    </div>

                <?php else: ?>

                    <div class="av-material-grid">

                        <?php foreach ($lista as $evaluacion): ?>

                            <?php

                            $estado = $evaluacion['estado_disponibilidad'];

                            $intento = $entregas[$evaluacion['id']] ?? null;

                            $nombreEvaluacion = $evaluacion['titulo']
                                ?: 'Borrador sin título';

                            $destino = $estado === 'borrador' && $esProfesor
                                ? $editor
                                : $base;

                            ?>

                            <article class="av-material-card av-evaluation-card">

                                <div class="av-evaluation-card-header">

                                    <h3>
                                        <?= escaparMaterial($nombreEvaluacion) ?>
                                    </h3>

                                    <?php if ($esProfesor): ?>

                                        <?php if ((int) $evaluacion['protegida'] === 1): ?>

                                            <span class="av-evaluation-protected"
                                                  title="No se puede eliminar: tiene intentos o notas"
                                                  role="img"
                                                  aria-label="Evaluación protegida contra eliminación">

                                                <svg viewBox="0 0 24 24"
                                                     width="19" height="19"
                                                     fill="none"
                                                     stroke="currentColor"
                                                     stroke-width="1.7"
                                                     stroke-linecap="round"
                                                     stroke-linejoin="round"
                                                     aria-hidden="true">

                                                    <rect x="5" y="10"
                                                          width="14" height="11"
                                                          rx="2"/>

                                                    <path d="M8 10V7a4 4 0 0 1 8 0v3"/>

                                                </svg>

                                            </span>

                                        <?php else: ?>

                                            <button type="button"
                                                    class="av-material-delete av-evaluation-delete"
                                                    data-evaluacion="<?= escaparMaterial($evaluacion['id']) ?>"
                                                    data-titulo="<?= escaparMaterial($nombreEvaluacion) ?>"
                                                    aria-label="Eliminar <?= escaparMaterial($nombreEvaluacion) ?>"
                                                    title="Eliminar evaluación">

                                                <svg viewBox="0 0 24 24"
                                                     width="19" height="19"
                                                     fill="none"
                                                     stroke="currentColor"
                                                     stroke-width="1.7"
                                                     stroke-linecap="round"
                                                     stroke-linejoin="round"
                                                     aria-hidden="true">

                                                    <path d="M3 6h18M8 6V4h8v2"/>
                                                    <path d="M5 6l1 14h12l1-14"/>
                                                    <path d="M10 10v6M14 10v6"/>

                                                </svg>

                                            </button>

                                        <?php endif; ?>

                                    <?php endif; ?>

                                </div>

                                <div class="av-evaluation-card-dates">

                                    <div class="av-evaluation-date">

                                        <span>Fecha de inicio</span>

                                        <strong>
                                            <?= escaparMaterial(
                                                mostrarFechaEvaluacion(
                                                    $evaluacion['disponible_desde']
                                                )
                                            ) ?>
                                        </strong>

                                    </div>

                                    <div class="av-evaluation-date">

                                        <span>Fecha de término</span>

                                        <strong>
                                            <?= escaparMaterial(
                                                mostrarFechaEvaluacion(
                                                    $evaluacion['disponible_hasta']
                                                )
                                            ) ?>
                                        </strong>

                                    </div>

                                </div>

                                <div class="av-material-card-actions">

                                    <?php if ($esProfesor): ?>

                                        <a class="av-material-open"
                                           href="<?= escaparMaterial(
                                               $destino . '&id=' . $evaluacion['id']
                                           ) ?>">

                                            <?= $estado === 'borrador'
                                                ? 'Editar evaluación'
                                                : 'Ver evaluación' ?>

                                        </a>

                                    <?php elseif (
                                        ($intento['estado'] ?? '') === 'calificada'
                                    ): ?>

                                        <a class="av-material-open"
                                           href="<?= escaparMaterial(
                                               'evaluacion_resultado.php?id='
                                               . $intento['id']
                                           ) ?>">

                                            Ver resultado

                                        </a>

                                    <?php elseif ($intento): ?>

                                        <a class="av-material-open"
                                           href="<?= escaparMaterial(
                                               $rendir . '&id=' . $evaluacion['id']
                                           ) ?>">

                                            <?= $estado === 'finalizada'
                                                ? 'Consultar intento'
                                                : 'Continuar evaluación' ?>

                                        </a>

                                    <?php elseif ($estado === 'disponible'): ?>

                                        <a class="av-material-open"
                                           href="<?= escaparMaterial(
                                               $base . '&id=' . $evaluacion['id']
                                           ) ?>">

                                            Ver evaluación

                                        </a>

                                    <?php else: ?>

                                        <span class="av-evaluation-disabled">

                                            <?= $estado === 'programada'
                                                ? 'Aún no disponible'
                                                : 'Evaluación finalizada' ?>

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php else: ?>

            <!-- Información de la evaluación -->
            <?php if ($esProfesor): ?>
            <section class="av-courses"
                     aria-labelledby="titulo-informacion">

                <div class="av-section-heading">

                    <h2 id="titulo-informacion">
                        Información de la evaluación
                    </h2>

                </div>

                <div class="av-notes-summary">

                    <article class="av-notes-stat av-evaluation-detail-stat">

                        <span>Fecha de inicio</span>

                        <strong>
                            <?= escaparMaterial(
                                mostrarFechaEvaluacion(
                                    $seleccion['disponible_desde']
                                )
                            ) ?>
                        </strong>

                    </article>

                    <article class="av-notes-stat av-evaluation-detail-stat">

                        <span>Fecha de término</span>

                        <strong>
                            <?= escaparMaterial(
                                mostrarFechaEvaluacion(
                                    $seleccion['disponible_hasta']
                                )
                            ) ?>
                        </strong>

                    </article>

                    <article class="av-notes-stat av-evaluation-detail-stat">

                        <span>Duración máxima</span>

                        <strong>60 minutos</strong>

                    </article>

                </div>

            </section>

            <?php if ($esProfesor): ?>
                <?php endif; ?>
                <!-- Preguntas del profesor -->

                <section class="av-courses"
                         aria-labelledby="titulo-preguntas">

                    <div class="av-section-heading">

                        <h2 id="titulo-preguntas">
                            Preguntas de la evaluación
                        </h2>

                        <span class="av-course-count">
                            <?= count($preguntas) ?> preguntas
                        </span>

                    </div>

                    <?php if (!$preguntas): ?>

                        <div class="av-empty">
                            Esta evaluación todavía no tiene preguntas.
                        </div>

                    <?php else: ?>

                        <div class="av-evaluation-question-list">

                            <?php foreach ($preguntas as $indice => $pregunta): ?>

                                <article class="av-material-card av-evaluation-question">

                                    <div class="av-evaluation-question-heading">

                                        <span>
                                            Pregunta <?= $indice + 1 ?>
                                        </span>

                                        <span>
                                            <?= escaparMaterial(
                                                (string) $pregunta['puntaje']
                                            ) ?> puntos
                                        </span>

                                    </div>

                                    <h3>
                                        <?= escaparMaterial(
                                            $pregunta['enunciado']
                                        ) ?>
                                    </h3>

                                    <div class="av-evaluation-options">

                                        <?php foreach (
                                            $pregunta['alternativas'] as $alternativa
                                        ): ?>

                                            <div class="av-evaluation-option <?= $alternativa['es_correcta']
                                                ? 'av-evaluation-correct'
                                                : '' ?>">

                                                <span>
                                                    <?= escaparMaterial(
                                                        $alternativa['texto']
                                                    ) ?>
                                                </span>

                                                <?php if ($alternativa['es_correcta']): ?>

                                                    <small>Correcta</small>

                                                <?php endif; ?>

                                            </div>

                                        <?php endforeach; ?>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <?php if (!$seleccion['publicada_en']): ?>

                        <a class="av-notes-button"
                           href="<?= escaparMaterial(
                               $editor . '&id=' . $seleccion['id']
                           ) ?>">

                            Editar evaluación

                        </a>

                    <?php endif; ?>

                </section>

            <?php else: ?>

                <!-- Acceso del estudiante -->

                <?php

                $estado = $seleccion['estado_disponibilidad'];

                $intento = $entregas[$seleccion['id']] ?? null;

                ?>

                <section class="av-courses">

                    <div class="av-material-card av-evaluation-access">
                <h2>Información de la evaluación</h2>

<div class="av-prueba-informacion">

    <p>
        <strong>Fecha y hora de apertura:</strong>
        <?= escaparMaterial(
            mostrarFechaEvaluacion(
                $seleccion['disponible_desde']
            )
        ) ?>
    </p>

    <p>
        <strong>Fecha y hora de cierre:</strong>
        <?= escaparMaterial(
            mostrarFechaEvaluacion(
                $seleccion['disponible_hasta']
            )
        ) ?>
    </p>

    <p><strong>Duración máxima:</strong> 60 minutos</p>

    <p><strong>Intentos permitidos:</strong> 1 intento</p>

</div>

                        <?php if (
                            ($intento['estado'] ?? '') === 'calificada'
                        ): ?>

                            <h2>Evaluación completada</h2>

                            <p>
                                Ya rendiste esta evaluación.
                                Puedes consultar tu resultado.
                            </p>

                            <a class="av-notes-button"
                               href="<?= escaparMaterial(
                                   'evaluacion_resultado.php?id=' . $intento['id']
                               ) ?>">

                                Ver resultado

                            </a>

                        <?php elseif ($intento): ?>

                            <h2>Intento registrado</h2>

                            <p>
                                Consulta el estado de tu evaluación.
                                El tiempo disponible se controla desde el servidor.
                            </p>

                            <a class="av-notes-button"
                               href="<?= escaparMaterial(
                                   $rendir . '&id=' . $seleccion['id']
                               ) ?>">

                                <?= $estado === 'finalizada'
                                    ? 'Consultar intento'
                                    : 'Continuar evaluación' ?>

                            </a>

                        <?php elseif ($estado === 'disponible'): ?>

                            <h2>Instrucciones de la evaluación</h2>

                            <p>
                                Dispones de un único intento y un máximo
                                de 60 minutos, sin superar la fecha de cierre.
                            </p>

                            <a class="av-notes-button"
                               href="<?= escaparMaterial(
                                   $rendir . '&id=' . $seleccion['id']
                               ) ?>">

                                Rendir evaluación

                            </a>

                        <?php elseif ($estado === 'programada'): ?>

                            <h2>Evaluación programada</h2>

                            <p>
                                Podrás rendir esta evaluación a partir de
                                la fecha y hora de inicio establecidas.
                            </p>

                        <?php else: ?>

                            <h2>Evaluación finalizada</h2>

                            <p>
                                El período de disponibilidad ha terminado.
                                Ya no puedes iniciar un intento.
                            </p>

                        <?php endif; ?>

                    </div>

                </section>

            <?php endif; ?>

        <?php endif; ?>

    </main>

    <!-- Confirmar eliminación -->

    <?php if ($esProfesor && !$seleccion): ?>

        <dialog id="modal-eliminar-evaluacion"
                class="av-material-modal"
                aria-labelledby="titulo-eliminar-evaluacion">

            <div class="av-material-modal-icon">

                <svg viewBox="0 0 24 24"
                     width="25" height="25"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.7"
                     stroke-linecap="round"
                     stroke-linejoin="round"
                     aria-hidden="true">

                    <path d="M3 6h18M8 6V4h8v2"/>
                    <path d="M5 6l1 14h12l1-14"/>
                    <path d="M10 10v6M14 10v6"/>

                </svg>

            </div>

            <h2 id="titulo-eliminar-evaluacion">
                ¿Eliminar evaluación?
            </h2>

            <p>
                Se eliminará permanentemente
                <strong id="nombre-evaluacion-eliminar"></strong>,
                incluyendo sus preguntas y alternativas.
            </p>

            <p>
                Esta acción no se puede deshacer.
            </p>

            <form method="post"
                  action="<?= escaparMaterial($base) ?>"
                  id="form-eliminar-evaluacion">

                <input type="hidden"
                       name="accion"
                       value="eliminar">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= escaparMaterial(
                           $_SESSION['csrf_eliminar_evaluacion']
                       ) ?>">

                <input type="hidden"
                       name="evaluacion_id"
                       id="evaluacion-eliminar">

                <div class="av-material-modal-actions">

                    <button type="button"
                            id="cancelar-eliminar-evaluacion"
                            class="av-material-cancel"
                            autofocus>

                        Cancelar

                    </button>

                    <button type="submit"
                            class="av-material-confirm">

                        Eliminar evaluación

                    </button>

                </div>

            </form>

        </dialog>

    <?php endif; ?>

    <?php if ($esProfesor): ?>

        <?php require __DIR__ . '/includes/footer.php'; ?>

    <?php endif; ?>

</body>

</html>