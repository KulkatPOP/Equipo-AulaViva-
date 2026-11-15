<?php

require_once __DIR__ . '/config/rol.php';

exigirRol('profesor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$opcionesId = ['options' => ['min_range' => 1]];
$aulaId = filter_input(INPUT_POST, 'aula_id', FILTER_VALIDATE_INT, $opcionesId);
$alumnoId = filter_input(INPUT_POST, 'alumno_id', FILTER_VALIDATE_INT, $opcionesId);
if (!$aulaId || !$alumnoId) {
    http_response_code(400);
    exit('Aula o alumno inválido.');
}

$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['csrf_desasignar_alumno'])
    || !hash_equals($_SESSION['csrf_desasignar_alumno'], $token)) {
    http_response_code(403);
    exit('El formulario no es válido. Vuelve al aula e inténtalo nuevamente.');
}

try {
    require_once __DIR__ . '/config/conexion.php';

    $stmt = $pdo->prepare(
        "WITH aula_autorizada AS (
            SELECT a.id, a.colegio_id
            FROM aulas AS a
            INNER JOIN aula_profesores AS ap ON ap.aula_id = a.id
            WHERE a.id = :aula_id
              AND a.activo = TRUE
              AND a.colegio_id = :colegio_id
              AND ap.profesor_id = :profesor_id
            FOR SHARE OF a, ap
        ), alumno_elegible AS (
            SELECT a.id AS aula_id, u.id AS alumno_id
            FROM aula_autorizada AS a
            INNER JOIN usuarios AS u ON u.colegio_id = a.colegio_id
            WHERE u.id = :alumno_id AND u.rol = 'alumno' AND u.activo = TRUE
            FOR SHARE OF u
        ), desasignacion AS (
            DELETE FROM aula_alumnos AS aa
            USING alumno_elegible AS alumno
            WHERE aa.aula_id = alumno.aula_id AND aa.alumno_id = alumno.alumno_id
            RETURNING aa.alumno_id
        )
        SELECT CASE
            WHEN NOT EXISTS (SELECT 1 FROM alumno_elegible) THEN 'denegado'
            WHEN EXISTS (SELECT 1 FROM desasignacion) THEN 'desasignado'
            ELSE 'sin_relacion'
        END"
    );
    $stmt->execute([
        ':aula_id' => $aulaId,
        ':colegio_id' => (int) $_SESSION['colegio_id'],
        ':profesor_id' => (int) $_SESSION['usuario_id'],
        ':alumno_id' => $alumnoId,
    ]);
    $resultado = $stmt->fetchColumn();
} catch (RuntimeException $e) {
    $resultado = 'error';
}

if ($resultado === 'denegado') {
    http_response_code(403);
    exit('No tienes permiso para desasignar a este alumno de esta aula.');
}

$mensajes = [
    'desasignado' => 'Alumno desasignado del aula.',
    'sin_relacion' => 'El alumno ya no está asignado a esta aula.',
];

$_SESSION['aviso_desasignacion'] = [
    'aula_id' => $aulaId,
    'exito' => $resultado === 'desasignado',
    'mensaje' => $mensajes[$resultado] ?? 'No se pudo desasignar al alumno. Inténtalo nuevamente.',
];

header('Location: aula.php?id=' . $aulaId . '#alumnos', true, 303);
exit;
