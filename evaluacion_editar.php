<?php

require_once __DIR__ . '/config/evaluaciones.php';
exigirRol('profesor');
header('Cache-Control: private, no-store');

function responderEvaluacion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_THROW_ON_ERROR);
    exit;
}

function guardarEvaluacion(PDO $pdo, int $aulaId): never
{
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf_evaluacion']) || !hash_equals($_SESSION['csrf_evaluacion'], $csrf)) {
        responderEvaluacion(403, ['error' => 'El formulario ha caducado. Vuelve a abrirlo.']);
    }

    $entrada = file_get_contents('php://input', false, null, 0, 1048577);
    $datos = strlen($entrada) <= 1048576 ? json_decode($entrada, true) : null;
    $id = $datos['id'] ?? null;
    $preguntas = $datos['preguntas'] ?? null;
    $titulo = $datos['titulo'] ?? null;
    $publicar = $datos['publicar'] ?? null;
    if (!idEvaluacion($id) || !is_string($titulo) || !is_bool($publicar) || !is_array($preguntas)
        || !array_is_list($preguntas) || str_contains($titulo, "\0")) {
        responderEvaluacion(422, ['error' => 'Revisa los datos de la evaluación.']);
    }
    if ($publicar && (trim($titulo) === '' || !$preguntas)) {
        responderEvaluacion(422, ['error' => 'Ingresa un título y al menos una pregunta.']);
    }
    foreach ($preguntas as $pregunta) {
        $enunciado = $pregunta['enunciado'] ?? null;
        $puntaje = $pregunta['puntaje'] ?? null;
        $alternativas = $pregunta['alternativas'] ?? null;
        if (!is_string($enunciado) || str_contains($enunciado, "\0") || !is_array($alternativas)
            || !array_is_list($alternativas) || !is_string($puntaje)
            || preg_match('/\A[+]?[0-9]{1,30}(?:\.[0-9]{1,30})?\z/', $puntaje) !== 1) {
            responderEvaluacion(422, ['error' => 'Revisa el enunciado y el puntaje de cada pregunta.']);
        }
        $tieneCorrecta = false;
        foreach ($alternativas as $alternativa) {
            $texto = $alternativa['texto'] ?? null;
            $correcta = $alternativa['es_correcta'] ?? null;
            if (!is_string($texto) || str_contains($texto, "\0") || !is_bool($correcta) || ($publicar && trim($texto) === '')) {
                responderEvaluacion(422, ['error' => 'Revisa el texto de las alternativas.']);
            }
            $tieneCorrecta = $tieneCorrecta || $correcta;
        }
        if ($publicar && (trim($enunciado) === '' || count($alternativas) < 2 || !$tieneCorrecta)) {
            responderEvaluacion(422, ['error' => 'Cada pregunta necesita un enunciado, dos alternativas como mínimo y al menos una correcta.']);
        }
    }

    $pdo->beginTransaction();
    if (!aulaEvaluaciones($pdo, $aulaId, true)) {
        $pdo->rollBack();
        responderEvaluacion(403, ['error' => 'No tienes acceso a esta aula.']);
    }
    // El ID del navegador permite reintentar una creación cuya respuesta se perdió.
    $stmt = $pdo->prepare('INSERT INTO evaluaciones (id, aula_id, titulo) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING');
    $stmt->execute([$id, $aulaId, $titulo]);
    $stmt = $pdo->prepare('SELECT publicada_en FROM evaluaciones WHERE id = ? AND aula_id = ? FOR UPDATE');
    $stmt->execute([$id, $aulaId]);
    $borrador = $stmt->fetch();
    if (!$borrador || $borrador['publicada_en'] !== null) {
        $pdo->rollBack();
        responderEvaluacion(409, ['error' => 'La evaluación no está disponible para edición.']);
    }

    $pdo->prepare('UPDATE evaluaciones SET titulo = ? WHERE id = ?')->execute([$titulo, $id]);
    $pdo->prepare('DELETE FROM preguntas WHERE evaluacion_id = ?')->execute([$id]);
    $crearPregunta = $pdo->prepare('INSERT INTO preguntas (evaluacion_id, enunciado, orden, puntaje) VALUES (?, ?, ?, ?) RETURNING id');
    $crearAlternativa = $pdo->prepare('INSERT INTO alternativas (pregunta_id, texto, orden, es_correcta) VALUES (?, ?, ?, ?)');
    foreach ($preguntas as $orden => $pregunta) {
        $crearPregunta->execute([$id, $pregunta['enunciado'], $orden + 1, $pregunta['puntaje']]);
        $preguntaId = $crearPregunta->fetchColumn();
        $crearPregunta->closeCursor();
        foreach ($pregunta['alternativas'] as $posicion => $alternativa) {
            $crearAlternativa->execute([$preguntaId, $alternativa['texto'], $posicion + 1, $alternativa['es_correcta'] ? 'true' : 'false']);
        }
    }
    if ($publicar) {
        $stmt = $pdo->prepare('UPDATE evaluaciones SET publicada_en = CURRENT_TIMESTAMP WHERE id = ?
                               AND (SELECT SUM(puntaje) FROM preguntas WHERE evaluacion_id = ?) > 0');
        $stmt->execute([$id, $id]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            responderEvaluacion(422, ['error' => 'Debe asignar un puntaje mayor a 0']);
        }
    }
    $pdo->commit();
    responderEvaluacion(200, ['id' => $id, 'publicada' => $publicar]);
}

$aulaId = filter_input(INPUT_GET, 'aula_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
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

    $borrador = ['id' => null, 'titulo' => '', 'preguntas' => []];
    if ($id !== null) {
        $stmt = $pdo->prepare('SELECT id, titulo FROM evaluaciones WHERE aula_id = ? AND id = ? AND publicada_en IS NULL');
        $stmt->execute([$aulaId, $id]);
        $borrador = $stmt->fetch();
        if (!$borrador) {
            http_response_code(404);
            exit('Borrador no disponible.');
        }
        $borrador['preguntas'] = preguntasEvaluacion($pdo, $id, true);
    }
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($metodo === 'POST') {
        responderEvaluacion(500, ['error' => 'No se pudo guardar la evaluación.']);
    }
    http_response_code(500);
    exit('No se pudo abrir el formulario de evaluación.');
}
$_SESSION['csrf_evaluacion'] ??= bin2hex(random_bytes(32));
$clave = implode(':', ['us02', $_SESSION['colegio_id'], $_SESSION['usuario_id'], $aulaId]);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editar evaluación | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="js/evaluacion-editar.js" defer></script>
</head>
<body class="panel-docente">
<?php cabeceraEvaluaciones(); ?>
<main class="panel-contenido">
    <div class="panel-intro">
        <a class="panel-volver" href="evaluaciones.php?aula_id=<?= $aulaId ?>">← Evaluaciones</a>
        <h1><?= $id === null ? 'Crear evaluación' : 'Editar borrador' ?></h1>
        <p class="panel-resumen"><?= escaparMaterial($aula['nombre']) ?></p>
    </div>
    <p id="estado-evaluacion" role="status"></p>
    <noscript><p>Activa JavaScript para editar la evaluación y recuperar borradores sin conexión.</p></noscript>
    <form id="form-evaluacion" class="curso panel-editor-evaluacion" hidden
          data-clave="<?= escaparMaterial($clave) ?>"
          data-csrf="<?= escaparMaterial($_SESSION['csrf_evaluacion']) ?>"
          data-borrador="<?= escaparMaterial(json_encode($borrador, JSON_THROW_ON_ERROR)) ?>">
        <fieldset id="campos-evaluacion">
            <label>Título <input id="titulo-evaluacion" type="text"></label>
            <div id="preguntas-evaluacion"></div>
            <div class="panel-acciones-material">
                <button class="panel-boton" type="button" id="agregar-pregunta">Agregar pregunta</button>
                <button class="panel-boton" type="submit" value="guardar">Guardar borrador</button>
                <button class="panel-boton" type="submit" value="publicar">Publicar</button>
            </div>
        </fieldset>
    </form>
</main>
</body>
</html>
