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

$busquedaAlumno = trim(filter_input(INPUT_GET, 'alumno_usuario') ?? '');
$candidato = null;
$errorBusqueda = false;

if ($busquedaAlumno !== '') {
    try {
        $stmt = $pdo->prepare(
            "SELECT u.id, u.nombre, u.apellido, u.username
             FROM usuarios AS u
             WHERE u.username = :username
               AND u.colegio_id = :colegio_id
               AND u.rol = 'alumno'
               AND u.activo = TRUE
               AND NOT EXISTS (
                   SELECT 1 FROM aula_alumnos AS aa
                   WHERE aa.aula_id = :aula_id AND aa.alumno_id = u.id
               )
             LIMIT 1"
        );
        $stmt->execute([
            ':username' => $busquedaAlumno,
            ':colegio_id' => $colegioId,
            ':aula_id' => $aulaId,
        ]);
        $candidato = $stmt->fetch();
    } catch (PDOException $e) {
        $errorBusqueda = true;
    }
}

$_SESSION['csrf_asignar_alumno'] ??= bin2hex(random_bytes(32));
$avisoAsignacion = $_SESSION['aviso_asignacion'] ?? null;
if (($avisoAsignacion['aula_id'] ?? null) === $aulaId) {
    unset($_SESSION['aviso_asignacion']);
} else {
    $avisoAsignacion = null;
}

$_SESSION['csrf_desasignar_alumno'] ??= bin2hex(random_bytes(32));
$avisoDesasignacion = $_SESSION['aviso_desasignacion'] ?? null;
if (($avisoDesasignacion['aula_id'] ?? null) === $aulaId) {
    unset($_SESSION['aviso_desasignacion']);
} else {
    $avisoDesasignacion = null;
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
            <a class="panel-boton" href="materiales.php?aula_id=<?= $aulaId ?>">Materiales</a>
            <a class="panel-boton" href="evaluaciones.php?aula_id=<?= $aulaId ?>">Evaluaciones</a>
            <?php if ($totalAlumnos > 0): ?>
                <p class="panel-resumen"><?= $totalAlumnos ?> <?= $totalAlumnos === 1 ? 'alumno' : 'alumnos' ?></p>
            <?php endif; ?>
        </div>

        <section id="agregar-alumno" class="curso panel-asignacion" aria-labelledby="titulo-agregar-alumno">
            <h2 id="titulo-agregar-alumno" class="panel-subtitulo">Agregar alumno</h2>

            <?php if ($avisoAsignacion): ?>
                <p class="panel-resultado <?= $avisoAsignacion['exito'] ? 'panel-resultado-exito' : 'panel-resultado-error' ?>"
                    role="<?= $avisoAsignacion['exito'] ? 'status' : 'alert' ?>">
                    <?= htmlspecialchars($avisoAsignacion['mensaje'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <form method="get" action="aula.php#agregar-alumno">
                <input type="hidden" name="id" value="<?= $aulaId ?>">
                <label for="alumno-usuario">Nombre de usuario exacto</label>
                <div class="panel-asignacion-busqueda">
                    <input id="alumno-usuario" name="alumno_usuario" type="search" required
                        value="<?= htmlspecialchars($busquedaAlumno, ENT_QUOTES, 'UTF-8') ?>">
                    <button class="panel-boton" type="submit">Buscar</button>
                </div>
            </form>

            <?php if ($errorBusqueda): ?>
                <p class="panel-resultado panel-resultado-error" role="alert">No se pudo buscar al alumno. Inténtalo nuevamente.</p>
            <?php elseif ($candidato): ?>
                <form class="panel-asignacion-candidato" method="post" action="asignar_alumno.php">
                    <input type="hidden" name="aula_id" value="<?= $aulaId ?>">
                    <input type="hidden" name="alumno_id" value="<?= (int) $candidato['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_asignar_alumno'], ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <strong><?= htmlspecialchars($candidato['nombre'] . ' ' . $candidato['apellido'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <p>Usuario: <?= htmlspecialchars($candidato['username'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                    <button class="panel-boton" type="submit">Agregar alumno</button>
                </form>
            <?php elseif ($busquedaAlumno !== ''): ?>
                <p class="panel-resultado" role="status">No se encontró un alumno disponible con ese nombre de usuario.</p>
            <?php endif; ?>
        </section>

        <details class="curso panel-asignacion panel-invitacion">
            <summary>Invitar alumno</summary>
            <form id="form-invitar-alumno" action="api/v1/invitaciones/" method="post" hidden>
                <div class="panel-invitacion-campos">
                    <div>
                        <label for="invitacion-email">Correo electrónico</label>
                        <input type="email" id="invitacion-email" name="email" required>
                    </div>
                    <div>
                        <label for="invitacion-rut">RUT</label>
                        <input type="text" id="invitacion-rut" name="rut" required>
                    </div>
                    <div>
                        <label for="invitacion-usuario">Nombre de usuario</label>
                        <input type="text" id="invitacion-usuario" name="username" required>
                    </div>
                </div>
                <button type="submit" class="panel-boton">Crear invitación</button>
            </form>
            <p id="mensaje-invitacion" class="panel-resultado" role="status"></p>
            <div id="resultado-invitacion" hidden>
                <label for="enlace-invitacion">Enlace de activación:</label>
                <div class="panel-asignacion-busqueda">
                    <input type="text" id="enlace-invitacion" readonly>
                    <button type="button" id="copiar-invitacion" class="panel-boton">Copiar enlace</button>
                </div>
                <p id="mensaje-copia-invitacion" role="status"></p>
            </div>
        </details>
        <script src="js/invitar-alumno.js" defer></script>

        <section id="alumnos" aria-labelledby="titulo-alumnos">
            <h2 id="titulo-alumnos" class="panel-subtitulo">Alumnos</h2>

            <?php if ($avisoDesasignacion): ?>
                <p class="panel-resultado <?= $avisoDesasignacion['exito'] ? 'panel-resultado-exito' : 'panel-resultado-error' ?>"
                    role="<?= $avisoDesasignacion['exito'] ? 'status' : 'alert' ?>">
                    <?= htmlspecialchars($avisoDesasignacion['mensaje'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>

            <?php if (!$alumnos): ?>
                <p class="curso panel-aviso">No hay alumnos asignados a esta aula.</p>
            <?php else: ?>
                <div class="panel-buscar-alumno" hidden>
                    <label for="buscar-alumno">Buscar alumno</label>
                    <input id="buscar-alumno" type="search" placeholder="Buscar por nombre o usuario..."
                        aria-controls="listado-alumnos">
                </div>
                <p id="aviso-busqueda-alumnos" role="status"></p>
                <ul id="listado-alumnos" class="panel-tarjetas panel-tarjetas-alumnos" role="list">
                    <?php foreach ($alumnos as $alumno): ?>
                        <li class="curso">
                            <h3><?= htmlspecialchars(
                                $alumno['nombre'] . ' ' . $alumno['apellido'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></h3>
                            <p>Usuario: <span data-usuario-alumno><?= htmlspecialchars(
                                $alumno['username'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></span></p>
                            <button type="button" class="panel-boton-secundario" data-desasignar="<?= (int) $alumno['id'] ?>"
                                aria-haspopup="dialog"
                                aria-label="Desasignar a <?= htmlspecialchars($alumno['nombre'] . ' ' . $alumno['apellido'], ENT_QUOTES, 'UTF-8') ?>">
                                Desasignar
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
        <?php if ($alumnos): ?>
            <dialog class="panel-confirmacion" id="confirmar-desasignacion"
                aria-labelledby="titulo-desasignacion" aria-describedby="alumno-desasignacion detalle-desasignacion">
                <h2 id="titulo-desasignacion">Desasignar alumno</h2>
                <p id="alumno-desasignacion"></p>
                <p id="detalle-desasignacion">Se quitará a este alumno de esta aula. Su cuenta y sus otras aulas se conservarán.</p>
                <div class="panel-confirmacion-acciones">
                    <form method="dialog">
                        <button type="submit" class="panel-boton-secundario" autofocus>Cancelar</button>
                    </form>
                    <form method="post" action="desasignar_alumno.php">
                        <input type="hidden" name="aula_id" value="<?= $aulaId ?>">
                        <input type="hidden" name="alumno_id" id="id-desasignacion">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_desasignar_alumno'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="panel-boton">Desasignar</button>
                    </form>
                </div>
            </dialog>
            <script src="js/desasignar-alumno.js" defer></script>
            <script src="js/buscar-alumnos.js" defer></script>
        <?php endif; ?>
    </main>
</body>
</html>
