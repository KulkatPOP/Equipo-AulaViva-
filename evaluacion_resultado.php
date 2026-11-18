<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/rendicion.php';
exigirRol('alumno');
header('Cache-Control: private, no-store');

$id = $_GET['id'] ?? null;
if (!idEvaluacion($id)) {
    http_response_code(404);
    exit('Resultado no disponible.');
}

try {
    require __DIR__ . '/config/conexion.php';
    $resultado = rendicionPDO($pdo, "
        SELECT e.*, v.titulo, round(e.puntaje_obtenido * 100 / e.puntaje_maximo, 2) AS porcentaje
        FROM entregas e JOIN evaluaciones v ON v.id = e.evaluacion_id
        JOIN aulas a ON a.id = v.aula_id JOIN usuarios u ON u.id = e.estudiante_id
        WHERE e.id = ? AND e.estudiante_id = ? AND u.activo AND u.rol = 'alumno'
          AND a.colegio_id = ? AND u.colegio_id = a.colegio_id
          AND v.publicada_en IS NOT NULL AND e.estado = 'calificada'
    ", [$id, $_SESSION['usuario_id'], $_SESSION['colegio_id']])->fetch();
    if (!$resultado) {
        http_response_code(404);
        exit('Resultado no disponible.');
    }
    $preguntas = rendicionPDO($pdo, '
        SELECT p.id AS pregunta, p.enunciado, a.texto, a.es_correcta, r.alternativa_id IS NOT NULL AS seleccionada
        FROM preguntas p JOIN alternativas a ON a.pregunta_id = p.id
        LEFT JOIN respuestas r ON r.alternativa_id = a.id AND r.entrega_id = ?
        WHERE p.evaluacion_id = ? ORDER BY p.orden, a.orden
    ', [$id, $resultado['evaluacion_id']])->fetchAll(PDO::FETCH_GROUP);
} catch (Throwable $e) {
    http_response_code(500);
    exit('No se pudo consultar el resultado.');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resultado | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body class="panel-docente">
<?php cabeceraEvaluaciones(); ?>
<main class="panel-contenido">
    <a class="panel-volver" href="mis_cursos.php">← Mis cursos</a>
    <h1><?= escaparMaterial($resultado['titulo']) ?></h1>
    <p>Entrega confirmada</p>
    <dl class="curso">
        <dt>Puntaje obtenido</dt><dd><?= escaparMaterial((string) $resultado['puntaje_obtenido']) ?></dd>
        <dt>Puntaje máximo</dt><dd><?= escaparMaterial((string) $resultado['puntaje_maximo']) ?></dd>
        <dt>Porcentaje</dt><dd><?= escaparMaterial(str_replace('.', ',', $resultado['porcentaje'])) ?> %</dd>
        <dt>Nota</dt><dd><?= escaparMaterial(str_replace('.', ',', $resultado['calificacion'])) ?></dd>
    </dl>
    <?php foreach ($preguntas as $opciones): ?>
        <section class="curso panel-pregunta">
            <h2><?= escaparMaterial($opciones[0]['enunciado']) ?></h2>
            <?php
            $seleccionadas = array_column(array_filter($opciones, static fn ($a) => $a['seleccionada']), 'texto');
            $correctas = array_column(array_filter($opciones, static fn ($a) => $a['es_correcta']), 'texto');
            ?>
            <p>Tu selección: <?= escaparMaterial($seleccionadas ? implode(', ', $seleccionadas) : 'Sin respuesta') ?></p>
            <p>Alternativas correctas: <?= escaparMaterial(implode(', ', $correctas)) ?></p>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
