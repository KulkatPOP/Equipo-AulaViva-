<?php

require_once __DIR__ . '/config/materiales.php';

exigirRol('alumno');

$error = false;
try {
    require_once __DIR__ . '/config/conexion.php';
    $stmt = $pdo->prepare(consultaAulasMateriales(false) . ' ORDER BY a.nombre, a.id');
    $stmt->execute(parametrosAulasMateriales());
    $cursos = $stmt->fetchAll();
} catch (RuntimeException $e) {
    http_response_code(500);
    $error = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis cursos | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
    <header>
        <a href="mis_cursos.php" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= escaparMaterial($_SESSION['nombre'] ?? '') ?></span>
        <nav aria-label="Navegación">
            <a href="mis_cursos.php" class="nav-link">Mis cursos</a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>
    <main class="panel-contenido">
        <div class="panel-intro"><h1>Mis cursos</h1></div>
        <?php if ($error): ?>
            <p class="curso panel-aviso panel-aviso-error" role="alert">No se pudieron cargar tus cursos.</p>
        <?php elseif (!$cursos): ?>
            <p class="curso panel-aviso">No tienes cursos asignados.</p>
        <?php else: ?>
            <ul class="panel-tarjetas panel-tarjetas-aulas" role="list">
                <?php foreach ($cursos as $curso): ?>
                    <li class="curso">
                        <h2><?= escaparMaterial($curso['nombre']) ?></h2>
                        <a class="panel-boton" href="materiales.php?aula_id=<?= (int) $curso['id'] ?>"
                           aria-label="Ver materiales de <?= escaparMaterial($curso['nombre']) ?>">Ver materiales</a>
                        <a class="panel-boton" href="evaluaciones.php?aula_id=<?= (int) $curso['id'] ?>">Evaluaciones</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </main>
</body>
</html>
