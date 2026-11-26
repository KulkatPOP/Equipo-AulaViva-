<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/notas.php';

exigirRol('profesor');

header('Cache-Control: private, no-store');

// Respuesta del servidor

function responderEvaluacion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($datos, JSON_THROW_ON_ERROR);

    exit;
}

// Guardar evaluación

function guardarEvaluacion(PDO $pdo, int $aulaId): never
{
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (
        !isset($_SESSION['csrf_evaluacion'])
        || !hash_equals($_SESSION['csrf_evaluacion'], $csrf)
    ) {
        responderEvaluacion(403, [
            'error' => 'El formulario ha caducado. Vuelve a abrirlo.'
        ]);
    }

    $entrada = file_get_contents(
        'php://input',
        false,
        null,
        0,
        1048577
    );

    $datos = is_string($entrada) && strlen($entrada) <= 1048576
        ? json_decode($entrada, true)
        : null;

    $id = $datos['id'] ?? null;
    $preguntas = $datos['preguntas'] ?? null;
    $titulo = $datos['titulo'] ?? null;
    $publicar = $datos['publicar'] ?? null;

    $desde = $datos['disponible_desde'] ?? '';
    $hasta = $datos['disponible_hasta'] ?? '';

    // Validación general

    if (
        !idEvaluacion($id)
        || !is_string($titulo)
        || !is_bool($publicar)
        || !is_array($preguntas)
        || !array_is_list($preguntas)
        || str_contains($titulo, "\0")
        || !is_string($desde)
        || !is_string($hasta)
    ) {
        responderEvaluacion(422, [
            'error' => 'Revisa los datos de la evaluación.'
        ]);
    }

    // Validación de disponibilidad

    $fechaDesde = fechaEvaluacion($desde);
    $fechaHasta = fechaEvaluacion($hasta);

    if ($publicar || $desde !== '' || $hasta !== '') {

        if (!$fechaDesde || !$fechaHasta) {
            responderEvaluacion(422, [
                'error' => 'Ingresa una fecha y hora de inicio y término válidas.'
            ]);
        }

        if ($fechaHasta <= $fechaDesde) {
            responderEvaluacion(422, [
                'error' => 'La fecha de término debe ser posterior a la de inicio.'
            ]);
        }

        if (
            $publicar
            && $fechaHasta <= new DateTimeImmutable('now')
        ) {
            responderEvaluacion(422, [
                'error' => 'La fecha de término debe ser posterior a la hora actual.'
            ]);
        }
    }

    // Requisitos de publicación

    if ($publicar && (trim($titulo) === '' || !$preguntas)) {
        responderEvaluacion(422, [
            'error' => 'Ingresa un título y al menos una pregunta.'
        ]);
    }

    // Validación de preguntas

    foreach ($preguntas as $indice => $pregunta) {

        if (!is_array($pregunta)) {
            responderEvaluacion(422, [
                'error' => 'La estructura de la pregunta no es válida.'
            ]);
        }

        $numero = $indice + 1;

        $enunciado = $pregunta['enunciado'] ?? null;
        $puntaje = $pregunta['puntaje'] ?? null;
        $alternativas = $pregunta['alternativas'] ?? null;
        $tipo = $pregunta['tipo_respuesta'] ?? null;

        // Tipo de respuesta

        if ($tipo === '1') {
            $tipo = 'unica';
        }

        if (!in_array($tipo, ['unica', 'multiple'], true)) {
            responderEvaluacion(422, [
                'error' => "Selecciona un tipo de respuesta válido en la pregunta $numero."
            ]);
        }

        $preguntas[$indice]['tipo_respuesta'] = $tipo;

        // Enunciado y puntaje

        if (
            !is_string($enunciado)
            || str_contains($enunciado, "\0")
            || !is_array($alternativas)
            || !array_is_list($alternativas)
            || !is_string($puntaje)
            || preg_match(
                '/\A[+]?[0-9]{1,30}(?:\.[0-9]{1,30})?\z/',
                $puntaje
            ) !== 1
        ) {
            responderEvaluacion(422, [
                'error' => "Revisa el enunciado y el puntaje de la pregunta $numero."
            ]);
        }

        // Alternativas

        $cantidadCorrectas = 0;

        foreach ($alternativas as $alternativa) {

            if (!is_array($alternativa)) {
                responderEvaluacion(422, [
                    'error' => "Las alternativas de la pregunta $numero no son válidas."
                ]);
            }

            $texto = $alternativa['texto'] ?? null;
            $correcta = $alternativa['es_correcta'] ?? null;

            if (
                !is_string($texto)
                || str_contains($texto, "\0")
                || !is_bool($correcta)
                || ($publicar && trim($texto) === '')
            ) {
                responderEvaluacion(422, [
                    'error' => "Revisa las alternativas de la pregunta $numero."
                ]);
            }

            if ($correcta) {
                $cantidadCorrectas++;
            }
        }

        // Selección única

        if (
            $tipo === 'unica'
            && $cantidadCorrectas > 1
        ) {
            responderEvaluacion(422, [
                'error' => "La pregunta $numero solo permite una respuesta correcta."
            ]);
        }

        // Permitir borradores incompletos

        if (!$publicar) {
            continue;
        }

        // Requisitos para publicar

        if (
            trim($enunciado) === ''
            || count($alternativas) < 2
        ) {
            responderEvaluacion(422, [
                'error' => "La pregunta $numero necesita un enunciado y al menos dos alternativas."
            ]);
        }

        if (
            $tipo === 'unica'
            && $cantidadCorrectas !== 1
        ) {
            responderEvaluacion(422, [
                'error' => "La pregunta $numero debe tener exactamente una respuesta correcta."
            ]);
        }

        if (
            $tipo === 'multiple'
            && $cantidadCorrectas < 2
        ) {
            responderEvaluacion(422, [
                'error' => "La pregunta $numero debe tener al menos dos respuestas correctas."
            ]);
        }
    }

    // Transacción

    $pdo->beginTransaction();

    if (!aulaEvaluaciones($pdo, $aulaId, true)) {

        $pdo->rollBack();

        responderEvaluacion(403, [
            'error' => 'No tienes acceso a esta aula.'
        ]);
    }


// Bloquear el libro antes de publicar.

if ($publicar) {
    bloquearLibroNotas($pdo, $aulaId);
}

    // Crear borrador

    $stmt = $pdo->prepare('
        INSERT INTO evaluaciones (
            id,
            aula_id,
            titulo
        )
        VALUES (?, ?, ?)
        ON CONFLICT (id) DO NOTHING
    ');

    $stmt->execute([
        $id,
        $aulaId,
        $titulo
    ]);

    // Permisos de edición

    $stmt = $pdo->prepare('
        SELECT publicada_en
        FROM evaluaciones
        WHERE id = ?
            AND aula_id = ?
        FOR UPDATE
    ');

    $stmt->execute([
        $id,
        $aulaId
    ]);

    $borrador = $stmt->fetch();

    if (!$borrador || $borrador['publicada_en'] !== null) {

        $pdo->rollBack();

        responderEvaluacion(409, [
            'error' => 'La evaluación no está disponible para edición.'
        ]);
    }

    // Actualizar título y disponibilidad

    $stmt = $pdo->prepare('
        UPDATE evaluaciones
        SET titulo = ?,
            disponible_desde = ?,
            disponible_hasta = ?
        WHERE id = ?
            AND aula_id = ?
    ');

    $stmt->execute([
        $titulo,
        $fechaDesde?->format(DATE_ATOM),
        $fechaHasta?->format(DATE_ATOM),
        $id,
        $aulaId
    ]);

    // Actualizar preguntas

    $pdo->prepare('
        DELETE FROM preguntas
        WHERE evaluacion_id = ?
    ')->execute([$id]);

    // Crear preguntas

    $crearPregunta = $pdo->prepare('
        INSERT INTO preguntas (
            evaluacion_id,
            enunciado,
            orden,
            puntaje,
            tipo_respuesta
        )
        VALUES (?, ?, ?, ?, ?)
        RETURNING id
    ');

    // Crear alternativas

    $crearAlternativa = $pdo->prepare('
        INSERT INTO alternativas (
            pregunta_id,
            texto,
            orden,
            es_correcta
        )
        VALUES (?, ?, ?, ?)
    ');

    foreach ($preguntas as $orden => $pregunta) {

        $crearPregunta->execute([
            $id,
            $pregunta['enunciado'],
            $orden + 1,
            $pregunta['puntaje'],
            $pregunta['tipo_respuesta']
        ]);

        $preguntaId = $crearPregunta->fetchColumn();

        $crearPregunta->closeCursor();

        foreach ($pregunta['alternativas'] as $posicion => $alternativa) {

            $crearAlternativa->execute([
                $preguntaId,
                $alternativa['texto'],
                $posicion + 1,
                $alternativa['es_correcta'] ? 'true' : 'false'
            ]);
        }
    }

    // Publicar evaluación

    if ($publicar) {

        $stmt = $pdo->prepare('
            UPDATE evaluaciones
            SET publicada_en = clock_timestamp()
            WHERE id = ?
                AND aula_id = ?
                AND publicada_en IS NULL
                AND disponible_desde IS NOT NULL
                AND disponible_hasta IS NOT NULL
                AND disponible_hasta > clock_timestamp()
                AND (
                    SELECT SUM(puntaje)
                    FROM preguntas
                    WHERE evaluacion_id = ?
                ) > 0
        ');

        $stmt->execute([
            $id,
            $aulaId,
            $id
        ]);

                if ($stmt->rowCount() !== 1) {

            $pdo->rollBack();

            responderEvaluacion(422, [
                'error' => 'Revisa el puntaje total y las fechas de disponibilidad.'
            ]);
        }

        // Sincronizar automáticamente el libro de notas.

        inicializarLibroNotas(
            $pdo,
            $aulaId,
            (int) $_SESSION['usuario_id']
        );
    }

    $pdo->commit();

    responderEvaluacion(200, [
        'id' => $id,
        'publicada' => $publicar
    ]);
}

// Parámetros de acceso

$aulaId = filter_input(
    INPUT_GET,
    'aula_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 2147483647
        ]
    ]
);

$id = $_GET['id'] ?? null;
$metodo = $_SERVER['REQUEST_METHOD'];

if (!in_array($metodo, ['GET', 'POST'], true)) {

    header('Allow: GET, POST');
    http_response_code(405);

    exit;
}

if (!$aulaId || ($id !== null && !idEvaluacion($id))) {

    http_response_code(400);

    exit('Solicitud inválida.');
}

// Consultar aula y borrador

try {

    require __DIR__ . '/config/conexion.php';

    if ($metodo === 'POST') {
        guardarEvaluacion($pdo, $aulaId);
    }

    $aula = aulaEvaluaciones($pdo, $aulaId);

    if (!$aula) {

        http_response_code(403);

        exit('No tienes acceso a esta aula.');
    }

    $borrador = [
        'id' => null,
        'titulo' => '',
        'disponible_desde' => '',
        'disponible_hasta' => '',
        'preguntas' => []
    ];

    if ($id !== null) {

        $stmt = $pdo->prepare('
            SELECT
                id,
                titulo,
                disponible_desde,
                disponible_hasta
            FROM evaluaciones
            WHERE aula_id = ?
                AND id = ?
                AND publicada_en IS NULL
        ');

        $stmt->execute([
            $aulaId,
            $id
        ]);

        $borrador = $stmt->fetch();

        if (!$borrador) {

            http_response_code(404);

            exit('Borrador no disponible.');
        }

        $borrador['disponible_desde'] = fechaFormularioEvaluacion(
            $borrador['disponible_desde']
        );

        $borrador['disponible_hasta'] = fechaFormularioEvaluacion(
            $borrador['disponible_hasta']
        );

        $borrador['preguntas'] = preguntasEvaluacion(
            $pdo,
            $id,
            true
        );
    }

} catch (RuntimeException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($metodo === 'POST') {

        responderEvaluacion(500, [
            'error' => 'No se pudo guardar la evaluación.'
        ]);
    }

    http_response_code(500);

    exit('No se pudo abrir el formulario de evaluación.');
}

// Seguridad de sesión

$_SESSION['csrf_evaluacion'] ??= bin2hex(random_bytes(32));

$_SESSION['clave_borradores_us02'] ??= base64_encode(
    random_bytes(32)
);

$clave = implode(':', [
    'us02',
    $_SESSION['colegio_id'],
    $_SESSION['usuario_id'],
    $aulaId
]);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>
        <?= $id === null ? 'Crear evaluación' : 'Editar evaluación' ?>
        | AulaIA
    </title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/evaluacion-editor.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/evaluacion-limpiar-borradores.js" defer></script>
    <script src="js/evaluacion-editar.js" defer></script>

</head>

<body class="av-dashboard av-editor-page">

    <!-- Header -->

    <header class="av-header">

        <a href="mis_aulas.php" class="av-logo">

            <img src="img/Logo.png" alt="">

            <span>AulaIA</span>

        </a>

        <div class="av-profile">

            <div class="av-profile-text">

                <strong>
                    <?= escaparMaterial($_SESSION['nombre'] ?? '') ?>
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

    <main class="av-editor-main">

        <!-- Encabezado -->

        <div class="av-editor-intro">

            <a href="evaluaciones.php?aula_id=<?= $aulaId ?>"
               class="av-editor-back">

                <svg viewBox="0 0 24 24"
                     width="18"
                     height="18"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     aria-hidden="true">

                    <path d="M19 12H5M12 19l-7-7 7-7"/>

                </svg>

                Volver a evaluaciones

            </a>

            <h1>

                <?= $id === null
                    ? 'Crear evaluación'
                    : 'Editar evaluación' ?>

            </h1>

            <p>

                <?= escaparMaterial($aula['nombre']) ?>

            </p>

        </div>

        <!-- Estado del formulario -->

        <p id="estado-evaluacion"
           class="av-editor-status"
           role="status"></p>

        <noscript>

            <p>
                Activa JavaScript para editar la evaluación
                y recuperar borradores sin conexión.
            </p>

        </noscript>

        <form id="form-evaluacion"
              class="av-editor-form"
              hidden
              data-clave="<?= escaparMaterial($clave) ?>"
              data-cifrado="<?= escaparMaterial(
                  $_SESSION['clave_borradores_us02']
              ) ?>"
              data-csrf="<?= escaparMaterial(
                  $_SESSION['csrf_evaluacion']
              ) ?>"
              data-borrador="<?= escaparMaterial(
                  json_encode($borrador, JSON_THROW_ON_ERROR)
              ) ?>">

            <fieldset id="campos-evaluacion"
                      class="av-editor-fields">

                <!-- Información general -->

                <section class="av-editor-card">

                    <h2>Información general</h2>

                    <label for="titulo-evaluacion">

                        Título de la evaluación

                        <input id="titulo-evaluacion"
                               type="text"
                               placeholder="Ej. Evaluación de Ciencias Naturales">

                    </label>

                    <div class="av-editor-dates">

                        <label for="fecha-desde">

                            Fecha de inicio

                            <input id="fecha-desde"
                                   type="date">

                        </label>

                        <label for="hora-desde">

                            Hora de inicio

                            <input id="hora-desde"
                                   type="time">

                        </label>

                        <label for="fecha-hasta">

                            Fecha de término

                            <input id="fecha-hasta"
                                   type="date">

                        </label>

                        <label for="hora-hasta">

                            Hora de término

                            <input id="hora-hasta"
                                   type="time">

                        </label>

                    </div>

                    <input id="disponible-desde" type="hidden">

                    <input id="disponible-hasta" type="hidden">

                    <p class="av-editor-help">

                        Cada estudiante dispondrá de un máximo
                        de 60 minutos, sin superar la fecha de cierre.
                        Horario de Chile continental.

                    </p>

                </section>

                <!-- Preguntas -->

                <section class="av-editor-questions">

                    <div class="av-editor-section-heading">

                        <h2>Preguntas</h2>

                        <span id="contador-preguntas">

                            0 preguntas

                        </span>

                    </div>

                    <div id="preguntas-evaluacion"></div>

                    <button type="button"
                            id="agregar-pregunta"
                            class="av-editor-add-question">

                        + Agregar pregunta

                    </button>

                </section>

                <!-- Acciones -->

                <section class="av-editor-card av-editor-actions">

                    <div class="av-editor-total">

                        <span>Puntaje total</span>

                        <strong id="puntaje-total">

                            0 puntos

                        </strong>

                    </div>

                    <div class="av-editor-buttons">

                        <button type="submit"
                                value="guardar"
                                class="av-editor-secondary">

                            Guardar borrador

                        </button>

                        <button type="submit"
                                value="publicar"
                                class="av-editor-primary">

                            Publicar evaluación

                        </button>

                    </div>

                </section>

            </fieldset>

        </form>

    </main>

</body>

</html>