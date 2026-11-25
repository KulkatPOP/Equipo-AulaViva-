<?php

require_once __DIR__ . '/config/rol.php';

// Permite utilizar una base de datos aislada en las pruebas.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/config/conexion.php';
}

/* SOLO PROFESORES */

exigirRol('profesor');

/* OBTENER DATOS DE LA SESIÓN */

$profesorId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

try {

    /* BUSCAR SOLO LAS AULAS ASIGNADAS AL PROFESOR Y A SU COLEGIO */

    $stmt = $pdo->prepare(
        'SELECT a.id, a.nombre
         FROM aulas AS a
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         WHERE ap.profesor_id = :profesor_id
           AND a.colegio_id = :colegio_id
           AND a.activo = TRUE
         ORDER BY a.nombre ASC'
    );

    $stmt->execute([
        ':profesor_id' => $profesorId,
        ':colegio_id' => $colegioId
    ]);

    $aulas = $stmt->fetchAll();

} catch (PDOException $e) {

    http_response_code(500);
    exit('No se pudieron cargar las aulas.');
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis aulas | Aula Viva</title>

    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="panel-docente">

    <header>
        <a href="mis_aulas.php" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>

        <span class="panel-identidad">
            <?= htmlspecialchars($_SESSION['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
        </span>

        <nav aria-label="Navegación">
            <a href="mis_aulas.php" class="nav-link activo" aria-current="page">Mis aulas</a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>

    <main class="panel-contenido">

        <div class="panel-intro">
            <h1>Mis aulas</h1>
            <p>Consulta tus aulas y administra sus materiales y evaluaciones.</p>
        </div>

        <?php if (!$aulas): ?>

            <p class="curso panel-aviso">
                No tienes aulas asignadas.
            </p>

        <?php else: ?>

            <ul class="panel-tarjetas panel-tarjetas-aulas" role="list">

                <?php foreach ($aulas as $aula): ?>

                    <li class="curso">

                        <h2>
                            <?= htmlspecialchars($aula['nombre'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>

                        <p>Materiales, evaluaciones y alumnos.</p>

                        <a class="panel-boton"
                           href="aula.php?id=<?= (int) $aula['id'] ?>">
                            Ver aula
                        </a>

                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>

    </main>

</body>
</html>