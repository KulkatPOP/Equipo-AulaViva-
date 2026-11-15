<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/rendicion.php';
exigirRol('alumno');
header('Cache-Control: private, no-store');

function responderRendicion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_THROW_ON_ERROR);
    exit;
}

$id = $_GET['id'] ?? null;
$aulaId = filter_input(INPUT_GET, 'aula_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
$metodo = $_SERVER['REQUEST_METHOD'];
if (!idEvaluacion($id) || !$aulaId || !in_array($metodo, ['GET', 'POST'], true)) {
    http_response_code(400);
    exit('Solicitud inválida.');
}
$alumno = (int) $_SESSION['usuario_id'];
$colegio = (int) $_SESSION['colegio_id'];
if ($metodo === 'POST') {
    if (!isset($_SESSION['csrf_rendicion']) || !hash_equals($_SESSION['csrf_rendicion'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        responderRendicion(403, ['error' => 'Vuelve a abrir la evaluación para continuar.']);
    }
    $entrada = file_get_contents('php://input', false, null, 0, 1048577);
    $datos = strlen($entrada) <= 1048576 ? json_decode($entrada, true) : null;
    $accion = $datos['accion'] ?? null;
    if (!in_array($accion, ['iniciar', 'estado', 'guardar', 'entregar', 'ayuda'], true)) {
        responderRendicion(422, ['error' => 'La solicitud no es válida.']);
    }
    session_write_close();
}

try {
    require __DIR__ . '/config/conexion.php';
    if ($metodo === 'POST') {
        $pdo->beginTransaction();
    }
    $evaluacion = rendicionPDO($pdo, "
        SELECT e.titulo, e.aula_id FROM evaluaciones e JOIN aulas a ON a.id = e.aula_id
        JOIN usuarios u ON u.colegio_id = a.colegio_id
        WHERE e.id = ? AND a.id = ? AND a.colegio_id = ? AND u.id = ?
            AND u.activo AND u.rol = 'alumno' AND e.publicada_en IS NOT NULL
        " . ($metodo === 'POST' ? 'FOR SHARE OF e, a, u' : ''),
        [$id, $aulaId, $colegio, $alumno])->fetch();
    if (!$evaluacion) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        responderRendicion(404, ['error' => 'Evaluación no disponible.']);
    }

    $matricula = aulaEvaluaciones($pdo, $aulaId, $metodo === 'POST');
    $consultaEntrega = 'SELECT * FROM entregas WHERE evaluacion_id = ? AND estudiante_id = ?';
    $entrega = rendicionPDO($pdo, $consultaEntrega . ($metodo === 'POST' ? ' FOR UPDATE' : ''), [$id, $alumno])->fetch();

    if (!$matricula && ($entrega['estado'] ?? '') !== 'calificada') {
        if ($pdo->inTransaction()) $pdo->rollBack();
        responderRendicion(403, ['error' => 'No tienes acceso a esta evaluación.']);
    }

    if ($metodo === 'POST') {
        if ($accion === 'iniciar' && !$entrega) {
            iniciarRendicion($pdo, $id, $alumno);
            $entrega = rendicionPDO($pdo, $consultaEntrega . ' FOR UPDATE', [$id, $alumno])->fetch();
        }
        if (!$entrega || ($accion !== 'iniciar' && ($datos['entrega'] ?? null) !== $entrega['id'])) {
            $pdo->rollBack();
            responderRendicion(404, ['error' => 'Intento no disponible.']);
        }
        $estado = rendicionEstado($pdo, $entrega['id']);
        if ($estado['estado'] === 'en_progreso' && $estado['restante_ms'] <= 0) {
            cerrarRendicion($pdo, $entrega);
            $estado = rendicionEstado($pdo, $entrega['id']);
        }

        $codigo = 200;
        $extra = [];
        if ($accion === 'ayuda') {
            $solicitud = $datos['solicitud'] ?? null;
            $mensaje = $datos['mensaje'] ?? null;
            if (!idEvaluacion($solicitud) || !is_string($mensaje) || str_contains($mensaje, "\0")
                || preg_match('/\A.{0,500}\z/us', $mensaje) !== 1) {
                $pdo->rollBack();
                responderRendicion(422, ['error' => 'Revisa el mensaje de ayuda.']);
            }
            rendicionPDO($pdo, 'INSERT INTO solicitudes_ayuda (id, entrega_id, mensaje) VALUES (?, ?, ?) ON CONFLICT (id) DO NOTHING',
                [$solicitud, $entrega['id'], $mensaje]);
            if (!rendicionPDO($pdo, 'SELECT 1 FROM solicitudes_ayuda WHERE id = ? AND entrega_id = ?', [$solicitud, $entrega['id']])->fetchColumn()) {
                $pdo->rollBack();
                responderRendicion(409, ['error' => 'Identificador de solicitud en uso.']);
            }
            $extra['ayuda_recibida'] = true;
        } elseif (in_array($accion, ['guardar', 'entregar'], true) && $estado['estado'] === 'en_progreso') {
            $selecciones = $datos['selecciones'] ?? null;
            if (!is_array($selecciones) || !array_is_list($selecciones)) {
                $pdo->rollBack();
                responderRendicion(422, ['error' => 'Revisa las alternativas seleccionadas.']);
            }
            foreach ($selecciones as $opcion) {
                if (!is_string($opcion) || preg_match('/\A[1-9][0-9]{0,18}\z/', $opcion) !== 1
                    || (strlen($opcion) === 19 && strcmp($opcion, '9223372036854775807') > 0)) {
                    $pdo->rollBack();
                    responderRendicion(422, ['error' => 'Alternativa inválida.']);
                }
            }
            $selecciones = array_values(array_unique($selecciones));
            if (($datos['revision'] ?? null) !== (int) $estado['revision_respuestas']) {
                $codigo = 409;
                $extra['error'] = 'Otra pestaña guardó respuestas más recientes.';
            } else {
                $guardadas = guardarRespuestasRendicion($pdo, $entrega, $selecciones);
                if (!$guardadas && rendicionEstado($pdo, $entrega['id'])['restante_ms'] > 0) {
                    $pdo->rollBack();
                    responderRendicion(422, ['error' => 'Una alternativa no pertenece a esta evaluación.']);
                }
                if (!$guardadas || $accion === 'entregar') {
                    cerrarRendicion($pdo, $entrega);
                }
            }
            $estado = rendicionEstado($pdo, $entrega['id']);
        }
        $pdo->commit();
        responderRendicion($codigo, $estado + $extra);
    }

    $preguntas = rendicionPDO($pdo, '
        SELECT p.id AS pregunta, p.enunciado, a.id AS alternativa, a.texto
        FROM preguntas p JOIN alternativas a ON a.pregunta_id = p.id
        WHERE p.evaluacion_id = ? ORDER BY p.orden, a.orden
    ', [$id])->fetchAll(PDO::FETCH_GROUP);
    $_SESSION['csrf_rendicion'] ??= bin2hex(random_bytes(32));
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    responderRendicion(500, ['error' => 'No se pudo procesar la evaluación.']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rendir evaluación | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="js/evaluacion-rendir.js" defer></script>
</head>
<body class="panel-docente">
<?php cabeceraEvaluaciones(); ?>
<main class="panel-contenido">
    <a class="panel-volver" href="evaluaciones.php?aula_id=<?= $aulaId ?>">← Evaluaciones</a>
    <h1><?= escaparMaterial($evaluacion['titulo']) ?></h1>
    <div id="rendicion" data-csrf="<?= escaparMaterial($_SESSION['csrf_rendicion']) ?>"
         data-clave="<?= escaparMaterial("us03:$colegio:$alumno:$id") ?>"
         data-entrega="<?= escaparMaterial($entrega['id'] ?? '') ?>">
        <button class="panel-boton" id="iniciar" type="button" hidden>Iniciar evaluación</button>
        <p id="estado-rendicion" role="status"></p>
        <p id="tiempo-rendicion" role="timer"></p>
        <p id="progreso-rendicion"></p>
        <form id="respuestas" hidden>
            <fieldset id="preguntas-rendicion">
                <legend>Preguntas</legend>
                <?php foreach ($preguntas as $opciones): ?>
                    <fieldset class="curso panel-pregunta">
                        <legend><?= escaparMaterial($opciones[0]['enunciado']) ?></legend>
                        <?php foreach ($opciones as $opcion): ?>
                            <label><input type="checkbox" value="<?= escaparMaterial((string) $opcion['alternativa']) ?>">
                                <?= escaparMaterial($opcion['texto']) ?></label><br>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endforeach; ?>
            </fieldset>
            <button class="panel-boton" type="submit" id="enviar-rendicion">Finalizar y Enviar</button>
        </form>
        <dialog id="confirmar-rendicion" aria-labelledby="advertencia-rendicion">
            <p id="advertencia-rendicion">Tienes preguntas sin contestar. ¿Deseas enviar de todas formas?</p>
            <form method="dialog">
                <button value="cancelar" autofocus>Cancelar</button>
                <button value="enviar">Enviar</button>
            </form>
        </dialog>
        <details id="ayuda-rendicion" hidden>
            <summary>Contactar al profesor</summary>
            <form id="solicitud-rendicion">
                <label>Descripción opcional <textarea id="mensaje-rendicion" maxlength="500"></textarea></label>
                <button class="panel-boton" type="submit">Solicitar ayuda</button>
            </form>
            <p id="estado-ayuda" role="status"></p>
        </details>
    </div>
</main>
</body>
</html>
