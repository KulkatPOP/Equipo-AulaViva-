<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/tutor/IngestaTutor.php';

$opciones = getopt('', ['material:', 'profesor:']);
$material = filter_var($opciones['material'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$profesor = filter_var($opciones['profesor'] ?? '', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
if (!$material || !$profesor) {
    fwrite(STDERR, "Uso: php cli/tutor_ingestar.php --material=ID --profesor=ID\n");
    exit(1);
}

try {
    require __DIR__ . '/../config/conexion.php';
    $resultado = (new IngestaTutor(
        new RepositorioFragmentosTutor($pdo),
        PdfTextExtractor::desdeEntorno(),
        OllamaEmbeddingProvider::desdeEntorno()
    ))->procesar($material, $profesor);
    fwrite(STDOUT, json_encode($resultado, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, ($error instanceof RuntimeException && !$error instanceof PDOException
        ? $error->getMessage() : 'No se pudo completar la ingestión.') . "\n");
    exit(1);
}
