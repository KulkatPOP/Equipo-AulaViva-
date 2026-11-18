<?php

declare(strict_types=1);

require_once __DIR__ . '/config/tutor/TutorService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

function responderTutor(int $codigo, TutorResponse $respuesta): never
{
    http_response_code($codigo);
    echo json_encode($respuesta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

function limiteTutor(string $variable, int $predeterminado, int $maximo): int
{
    $valor = getenv($variable);
    if ($valor === false || $valor === '') {
        return $predeterminado;
    }
    $numero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximo]]);
    if ($numero === false) {
        throw new RuntimeException('Límite del tutor inválido.');
    }
    return $numero;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderTutor(405, new TutorResponse('VALIDATION_FAILED', 'Usa POST para consultar al Tutor IA.'));
}
$tipo = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
if ($tipo !== 'application/json' || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    responderTutor(415, new TutorResponse('VALIDATION_FAILED', 'La consulta debe enviarse como JSON desde AulaViva.'));
}

require_once __DIR__ . '/config/sesion.php';
if ($_SESSION['rol'] !== 'alumno') {
    responderTutor(403, new TutorResponse('INSUFFICIENT_EVIDENCE', 'No tienes acceso a esta aula.'));
}
$entrada = file_get_contents('php://input', false, null, 0, 8193);
$datos = is_string($entrada) && strlen($entrada) <= 8192 ? json_decode($entrada, true, 8) : null;
$aula = $datos['aula_id'] ?? null;
$pregunta = $datos['pregunta'] ?? null;
if (!is_array($datos) || count($datos) !== 2 || !is_int($aula) || $aula < 1 || $aula > 2147483647
    || !is_string($pregunta) || str_contains($pregunta, "\0") || preg_match('/\S/u', $pregunta) !== 1
    || mb_strlen($pregunta, 'UTF-8') > 1500) {
    responderTutor(400, new TutorResponse('VALIDATION_FAILED', 'Envía un aula y una pregunta de 1 a 1500 caracteres.'));
}

try {
    require __DIR__ . '/config/conexion.php';
    $segundos = limiteTutor('TUTOR_AI_TIMEOUT_SECONDS', 15, 120);
    $servicio = new TutorService(
        $pdo,
        new TutorRetriever($pdo, OllamaEmbeddingProvider::desdeEntorno($segundos * 1000), 'english'),
        new EvidenceGate(),
        OllamaAIProvider::desdeEntorno(),
        $segundos,
        limiteTutor('TUTOR_MAX_CONTEXT_FRAGMENTS', 3, 3),
        limiteTutor('TUTOR_MAX_ANSWER_CHARS', 1000, 1000)
    );
    if (!$servicio->aulaAutorizada($aula)) {
        responderTutor(403, new TutorResponse('INSUFFICIENT_EVIDENCE', 'No tienes acceso a esta aula.'));
    }
    $respuesta = $servicio->ask($aula, $pregunta);
    responderTutor(match ($respuesta->status) {
        'GENERATION_TIMEOUT' => 504, 'GENERATION_UNAVAILABLE' => 503, default => 200,
    }, $respuesta);
} catch (Throwable) {
    error_log('{"tutor_status":"GENERATION_UNAVAILABLE","fragments":0}');
    responderTutor(503, new TutorResponse('GENERATION_UNAVAILABLE', 'El Tutor IA no está disponible en este momento.'));
}
