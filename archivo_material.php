<?php

require_once __DIR__ . '/config/materiales.php';

header('Cache-Control: private, no-store');

$materialId = filter_input(INPUT_GET, 'material_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$material = false;
try {
    if ($materialId) {
        require_once __DIR__ . '/config/conexion.php';
        $consulta = consultaAulasMateriales($rolMateriales === 'profesor');
        $stmt = $pdo->prepare(
            "SELECT m.nombre_archivo, encode(m.contenido, 'base64') AS contenido
             FROM materiales AS m
             JOIN ($consulta) AS autorizada ON autorizada.id = m.aula_id
             WHERE m.id = :material_id"
        );
        $stmt->execute(parametrosAulasMateriales() + [':material_id' => $materialId]);
        $material = $stmt->fetch();
    }
} catch (RuntimeException $e) {
    http_response_code(500);
    exit('No se pudo consultar el archivo. Inténtalo nuevamente.');
}

$pdf = $material ? base64_decode($material['contenido'], true) : false;
if ($pdf === false || !str_starts_with($pdf, '%PDF-') || !str_ends_with(rtrim($pdf), '%%EOF')) {
    http_response_code(404);
    exit('El archivo no se encuentra disponible. Contacte al docente.');
}

$disposicion = filter_input(INPUT_GET, 'descargar') === '1' ? 'attachment' : 'inline';
$nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', $material['nombre_archivo']);
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
