<?php
require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';
exigirRol('apoderado');
header('Cache-Control: private, no-store');
$error = false;
$accesoDenegado = false;
$pupilos = [];
$rendimiento = [];
$seleccionado = null;
try {
    require __DIR__ . '/config/conexion.php';
    $pupilos = pupilosAutorizados($pdo, (int) $_SESSION['usuario_id'], (int) $_SESSION['colegio_id']);
    $seleccionado = isset($_GET['estudiante_id']) ? idApoderados($_GET['estudiante_id']) : ($pupilos[0]['id'] ?? null);
    if (isset($_GET['estudiante_id']) && ($seleccionado === null || !in_array($seleccionado, array_map('intval', array_column($pupilos, 'id')), true))) {
        http_response_code(403);
        $accesoDenegado = true;
    }
    if (!$accesoDenegado && $seleccionado !== null) {
        $rendimiento = rendimientoApoderado($pdo, (int) $_SESSION['usuario_id'], (int) $_SESSION['colegio_id'], (int) $seleccionado);
    }
} catch (Throwable $e) {
    http_response_code(500);
    $error = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rendimiento Académico | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/apoderados.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente panel-apoderado">
<header>
    <a href="panel_apoderado.php" class="logo"><img src="img/Logo.png" alt=""><span class="title-header">Aula Viva</span></a>
    <span class="panel-identidad"><?= escaparApoderados($_SESSION['nombre'] ?? '') ?></span>
    <?php if (!$error && !$accesoDenegado && $pupilos): ?>
        <form method="get" class="apoderado-selector">
            <label for="estudiante_id">Estudiante</label>
            <select id="estudiante_id" name="estudiante_id">
                <?php foreach ($pupilos as $pupilo): ?>
                    <option value="<?= (int) $pupilo['id'] ?>" <?= (int) $pupilo['id'] === (int) $seleccionado ? 'selected' : '' ?>><?= escaparApoderados($pupilo['nombre'] . ' ' . $pupilo['apellido']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="panel-boton" type="submit">Ver rendimiento</button>
        </form>
    <?php endif; ?>
    <nav aria-label="Navegación"><a href="panel_apoderado.php" class="nav-link" aria-current="page">Rendimiento Académico</a><a href="logout.php" class="nav-link">Cerrar sesión</a></nav>
</header>
<main class="panel-contenido">
    <h1>Rendimiento Académico</h1>
    <?php if ($accesoDenegado): ?>
        <div class="curso panel-aviso panel-aviso-error" role="alert">
            <p>Estudiante no disponible.</p>
            <a class="panel-volver" href="panel_apoderado.php">Volver al panel del apoderado</a>
        </div>
    <?php elseif ($error): ?>
        <p class="curso panel-aviso panel-aviso-error" role="alert">No se pudo consultar el rendimiento académico. Inténtalo nuevamente.</p>
    <?php elseif (!$pupilos): ?>
        <p class="curso panel-aviso">No hay estudiantes asociados a tu cuenta. Contacte a Coordinación</p>
    <?php elseif (!$rendimiento): ?>
        <p class="curso panel-aviso">No hay aulas asignadas a este estudiante.</p>
    <?php else: ?>
        <?php foreach ($rendimiento as $aula): ?>
            <section class="curso apoderado-asignatura">
                <h2><?= htmlspecialchars(escaparApoderados($aula['asignatura']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></h2>
                <p><?= htmlspecialchars(escaparApoderados($aula['nombre']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></p>
                <p><strong>Promedio actual:</strong> <?= htmlspecialchars($aula['promedio'] === null ? 'Sin calificaciones' : escaparApoderados(str_replace('.', ',', (string) $aula['promedio'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></p>
                <?php if ($aula['evaluaciones']): ?>
                    <h3>Últimas evaluaciones calificadas</h3>
                    <ul class="apoderado-evaluaciones">
                        <?php foreach ($aula['evaluaciones'] as $evaluacion): ?>
                            <li><strong><?= htmlspecialchars(escaparApoderados($evaluacion['titulo']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></strong><span>Nota: <?= htmlspecialchars(escaparApoderados(str_replace('.', ',', (string) $evaluacion['calificacion'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></span><span>Fecha de entrega: <?= htmlspecialchars(escaparApoderados(formatearFechaApoderados($evaluacion['fecha_entrega'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
</body>
</html>
