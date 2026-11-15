<?php

require_once __DIR__ . '/config/rol.php';

exigirRol('profesor');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Método no permitido.');
}

$aulaId = filter_input(INPUT_POST, 'aula_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$aulaId) {
    http_response_code(400);
    exit('Aula inválida.');
}

$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || !isset($_SESSION['csrf_asignar_alumno'])
    || !hash_equals($_SESSION['csrf_asignar_alumno'], $token)) {
    http_response_code(403);
    exit('El formulario no es válido. Vuelve al aula e inténtalo nuevamente.');
}

$alumnoId = filter_input(INPUT_POST, 'alumno_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$resultado = 'alumno_invalido';

if ($alumnoId) {
    try {
        require_once __DIR__ . '/config/conexion.php';

        // Autorizar e insertar en la misma sentencia. La clave de la relación evita duplicados concurrentes.
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
            ), asignacion AS (
                INSERT INTO aula_alumnos (aula_id, alumno_id)
                SELECT aula_id, alumno_id FROM alumno_elegible
                ON CONFLICT (aula_id, alumno_id) DO NOTHING
                RETURNING aula_id
            )
            SELECT CASE
                WHEN NOT EXISTS (SELECT 1 FROM aula_autorizada) THEN 'aula_denegada'
                WHEN NOT EXISTS (SELECT 1 FROM alumno_elegible) THEN 'alumno_invalido'
                WHEN EXISTS (SELECT 1 FROM asignacion) THEN 'agregado'
                ELSE 'duplicado'
            END"
        );
        $stmt->execute([
            ':aula_id' => $aulaId,
            ':colegio_id' => (int) $_SESSION['colegio_id'],
            ':profesor_id' => (int) $_SESSION['usuario_id'],
            ':alumno_id' => $alumnoId,
        ]);
        $resultado = $stmt->fetchColumn();
    } catch (PDOException | RuntimeException $e) {
        $resultado = 'error';
    }
}

if ($resultado === 'aula_denegada') {
    // La vista de destino mostrará el error de acceso habitual.
    header('Location: aula.php?id=' . $aulaId, true, 303);
    exit;
}

$mensajes = [
    'agregado' => 'Alumno agregado al aula.',
    'duplicado' => 'El alumno ya está asignado a esta aula.',
    'alumno_invalido' => 'El alumno no está disponible para esta aula.',
];

$_SESSION['aviso_asignacion'] = [
    'aula_id' => $aulaId,
    'exito' => $resultado === 'agregado',
    'mensaje' => $mensajes[$resultado] ?? 'No se pudo agregar al alumno. Inténtalo nuevamente.',
];

header('Location: aula.php?id=' . $aulaId . '#agregar-alumno', true, 303);
exit;
