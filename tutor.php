<?php

declare(strict_types=1);

require_once __DIR__ . '/config/materiales.php';

exigirRol('alumno');
header('Cache-Control: private, no-store');

$error = false;
try {
    require_once __DIR__ . '/config/conexion.php';
    $stmt = $pdo->prepare(consultaAulasMateriales(false) . ' ORDER BY a.nombre, a.id');
    $stmt->execute(parametrosAulasMateriales());
    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (RuntimeException $e) {
    http_response_code(500);
    $error = true;
    $aulas = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tutor IA | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/tutor.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="js/tutor.js" defer></script>
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
    <main class="panel-contenido tutor-panel">
        <div class="panel-intro">
            <h1>Tutor IA</h1>
            <p>Pregunta sobre los materiales de tu aula.</p>
        </div>

        <?php if ($error): ?>
            <p class="curso panel-aviso panel-aviso-error" role="alert">No se pudieron cargar tus aulas. Intenta nuevamente.</p>
        <?php elseif (!$aulas): ?>
            <p class="curso panel-aviso">Para consultar al Tutor IA, debes estar matriculado en un aula.</p>
        <?php else: ?>
            <form id="tutor-form" class="curso tutor-form" data-state="IDLE" method="post" action="tutor_ask.php">
                <?php if (count($aulas) > 1): ?>
                    <div class="tutor-campo">
                        <label for="tutor-aula">Aula</label>
                        <select id="tutor-aula" name="aula_id" required>
                            <?php foreach ($aulas as $aula): ?>
                                <option value="<?= (int) $aula['id'] ?>"><?= escaparMaterial($aula['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <p class="tutor-aula-nombre"><?= escaparMaterial($aulas[0]['nombre']) ?></p>
                    <input type="hidden" name="aula_id" value="<?= (int) $aulas[0]['id'] ?>">
                <?php endif; ?>
                <div class="tutor-campo">
                    <label for="tutor-pregunta">Pregunta</label>
                    <input id="tutor-pregunta" name="pregunta" type="text" maxlength="1500" autocomplete="off" required>
                </div>
                <button id="tutor-enviar" class="panel-boton" type="submit" disabled>Preguntar</button>
                <p id="tutor-estado" class="tutor-estado" role="status" aria-live="polite" aria-atomic="true"></p>
                <noscript><p>Activa JavaScript para consultar al Tutor IA.</p></noscript>
            </form>

            <section id="tutor-historial" class="tutor-historial" aria-labelledby="tutor-historial-titulo" hidden>
                <h2 id="tutor-historial-titulo">Conversación</h2>
                <ol id="tutor-respuestas" class="tutor-respuestas" role="list"></ol>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
