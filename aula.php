<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/conexion.php';

/* SOLO PROFESORES */

exigirRol('profesor');

/* VALIDAR ID DEL AULA */

$aulaId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$aulaId || $aulaId < 1) {
    http_response_code(400);
    mostrarErrorAula('Aula inválida.');
}

$profesorId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

try {

    /* COMPROBAR QUE EL PROFESOR PERTENECE AL AULA */

    $stmt = $pdo->prepare(
        'SELECT a.id, a.nombre
         FROM aulas AS a
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         WHERE a.id = :aula_id
           AND a.colegio_id = :colegio_id
           AND ap.profesor_id = :profesor_id
           AND a.activo = TRUE
         LIMIT 1'
    );

    $stmt->execute([
        ':aula_id' => $aulaId,
        ':colegio_id' => $colegioId,
        ':profesor_id' => $profesorId
    ]);

    $aula = $stmt->fetch();

    if (!$aula) {
        http_response_code(403);
        mostrarErrorAula('No tienes permiso para acceder a esta aula.');
    }

    /* OBTENER ALUMNOS DEL AULA */

    $stmt = $pdo->prepare(
        'SELECT u.id, u.rut, u.username, u.nombre, u.apellido
         FROM usuarios AS u
         INNER JOIN aula_alumnos AS aa
             ON aa.alumno_id = u.id
         WHERE aa.aula_id = :aula_id
           AND u.colegio_id = :colegio_id
           AND u.rol = :rol
           AND u.activo = TRUE
         ORDER BY u.apellido ASC, u.nombre ASC'
    );

    $stmt->execute([
        ':aula_id' => $aulaId,
        ':colegio_id' => $colegioId,
        ':rol' => 'alumno'
    ]);

    $alumnos = $stmt->fetchAll();

} catch (PDOException $e) {

    http_response_code(500);
    mostrarErrorAula('No se pudo cargar el aula.');
}

function mostrarErrorAula(string $mensaje): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detalle de aula | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
    <header>
        <a href="mis_aulas.php" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= htmlspecialchars($_SESSION['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <nav aria-label="Panel docente">
            <a href="mis_aulas.php" class="nav-link activo">Mis aulas</a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>
    <main class="panel-contenido">
        <h1>Detalle de aula</h1>
        <p class="curso panel-aviso panel-aviso-error"><?= htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') ?></p>
        <a class="panel-boton" href="mis_aulas.php">Volver a mis aulas</a>
    </main>
</body>
</html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($aula['nombre'], ENT_QUOTES, 'UTF-8') ?> | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
    <header>
        <a href="mis_aulas.php" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= htmlspecialchars($_SESSION['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
        <nav aria-label="Panel docente">
            <a href="mis_aulas.php" class="nav-link activo">Mis aulas</a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>
    <main class="panel-contenido">
        <?php $totalAlumnos = count($alumnos); ?>
        <div class="panel-intro panel-intro-aula">
            <a class="panel-volver" href="mis_aulas.php">← Volver a mis aulas</a>
            <h1><?= htmlspecialchars($aula['nombre'], ENT_QUOTES, 'UTF-8') ?></h1>
            <?php if ($totalAlumnos > 0): ?>
                <p class="panel-resumen"><?= $totalAlumnos ?> <?= $totalAlumnos === 1 ? 'alumno' : 'alumnos' ?></p>
            <?php endif; ?>
        </div>

        <section aria-labelledby="titulo-alumnos">
            <h2 id="titulo-alumnos" class="panel-subtitulo">Alumnos</h2>

            <?php if (!$alumnos): ?>
                <p class="curso panel-aviso">No hay alumnos asignados a esta aula.</p>
            <?php else: ?>
                <ul class="panel-tarjetas panel-tarjetas-alumnos" role="list">
                    <?php foreach ($alumnos as $alumno): ?>
                        <li class="curso">
                            <h3><?= htmlspecialchars(
                                $alumno['nombre'] . ' ' . $alumno['apellido'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></h3>
                            <p>Usuario: <?= htmlspecialchars(
                                $alumno['username'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></p>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
