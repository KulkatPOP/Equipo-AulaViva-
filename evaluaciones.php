<?php

require_once __DIR__ . '/config/evaluaciones.php';
header('Cache-Control: private, no-store');
$aulaId = filter_input(INPUT_GET, 'aula_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
$id = $_GET['id'] ?? null;
if (!$aulaId || ($id !== null && !idEvaluacion($id))) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

$profesor = $_SESSION['rol'] === 'profesor';
try {
    require __DIR__ . '/config/conexion.php';
    $aula = aulaEvaluaciones($pdo, $aulaId);
    if (!$aula) {
        http_response_code(403);
        exit('No tienes acceso a las evaluaciones de esta aula.');
    }

    $sql = 'SELECT id, titulo, publicada_en FROM evaluaciones WHERE aula_id = :aula';
    $params = ['aula' => $aulaId];
    if (!$profesor) {
        $sql .= ' AND publicada_en IS NOT NULL';
    }
    if ($id !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $id;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY publicada_en DESC NULLS FIRST, id');
    $stmt->execute($params);
    $lista = $stmt->fetchAll();
    if ($id !== null && !$lista) {
        http_response_code(404);
        exit('Evaluación no disponible.');
    }
    $seleccion = $id !== null ? $lista[0] : null;
    $preguntas = $seleccion ? preguntasEvaluacion($pdo, $id, $profesor) : [];
} catch (RuntimeException $e) {
    http_response_code(500);
    exit('No se pudieron consultar las evaluaciones.');
}
$base = 'evaluaciones.php?aula_id=' . $aulaId;
$editor = 'evaluacion_editar.php?aula_id=' . $aulaId;
$volver = $seleccion ? $base : ($profesor ? 'aula.php?id=' . $aulaId : 'mis_cursos.php');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evaluaciones | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
<?php cabeceraEvaluaciones(); ?>
<main class="panel-contenido">
    <div class="panel-intro">
        <a class="panel-volver" href="<?= escaparMaterial($volver) ?>">← Volver</a>
        <h1><?= escaparMaterial($seleccion['titulo'] ?? 'Evaluaciones') ?></h1>
        <p class="panel-resumen"><?= escaparMaterial($aula['nombre']) ?></p>
    </div>
    <?php if ($seleccion): ?>
        <?php if (!$profesor): ?>
            <p><a class="panel-boton" href="<?= escaparMaterial('evaluacion_rendir.php?aula_id=' . $aulaId . '&id=' . $id) ?>">Rendir evaluación</a></p>
        <?php endif; ?>
        <ol>
            <?php foreach ($preguntas as $pregunta): ?>
                <li class="curso panel-pregunta">
                    <h2><?= escaparMaterial($pregunta['enunciado']) ?></h2>
                    <?php if ($profesor): ?><p><?= escaparMaterial((string) $pregunta['puntaje']) ?> puntos</p><?php endif; ?>
                    <ul>
                        <?php foreach ($pregunta['alternativas'] as $alternativa): ?>
                            <li><?= escaparMaterial($alternativa['texto']) ?><?= $profesor && $alternativa['es_correcta'] ? ' (correcta)' : '' ?></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php else: ?>
        <?php if ($profesor): ?><p><a class="panel-boton" href="<?= $editor ?>">Crear evaluación</a></p><?php endif; ?>
        <?php if (!$lista): ?><p class="curso panel-aviso">No hay evaluaciones disponibles.</p><?php endif; ?>
        <ul class="panel-tarjetas panel-tarjetas-aulas">
            <?php foreach ($lista as $evaluacion): ?>
                <li class="curso">
                    <h2><?= escaparMaterial($evaluacion['titulo'] ?: 'Borrador sin título') ?></h2>
                    <?php if ($profesor): ?><p><?= $evaluacion['publicada_en'] ? 'Publicada' : 'Borrador' ?></p><?php endif; ?>
                    <?php $destino = $profesor && !$evaluacion['publicada_en'] ? $editor : $base; ?>
                    <a class="panel-boton" href="<?= escaparMaterial($destino . '&id=' . $evaluacion['id']) ?>">
                        <?= $profesor && !$evaluacion['publicada_en'] ? 'Editar borrador' : 'Ver evaluación' ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
