<?php

require_once __DIR__ . '/config/rol.php';

exigirRol('profesor');

$colegioId = filter_var($_SESSION['colegio_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($colegioId === false) {
    http_response_code(403);
    exit('La sesión no tiene un colegio válido.');
}

header('Cache-Control: no-store');

$estados = ['Pendiente', 'Utilizada', 'Vencida'];
$estado = filter_input(INPUT_GET, 'estado') ?? '';
if (!in_array($estado, $estados, true)) {
    $estado = '';
}
$busqueda = trim(filter_input(INPUT_GET, 'buscar') ?: '');
$invitaciones = [];
$error = false;

try {
    require_once __DIR__ . '/config/conexion.php';

    $stmt = $pdo->prepare(
        "WITH invitaciones_colegio AS (
            SELECT id, username, email, rut, expira_en,
                   CASE
                       WHEN usado THEN 'Utilizada'
                       WHEN expira_en < CURRENT_TIMESTAMP THEN 'Vencida'
                       ELSE 'Pendiente'
                   END AS estado
            FROM invitaciones
            WHERE colegio_id = :colegio_id
        )
        SELECT username, email, rut, expira_en, estado
        FROM invitaciones_colegio
        CROSS JOIN (SELECT lower(CAST(:buscar AS text)) AS termino) AS filtro
        WHERE estado = COALESCE(NULLIF(:estado, ''), estado)
          AND (strpos(lower(username), termino) > 0
               OR strpos(lower(email), termino) > 0
               OR strpos(lower(rut), termino) > 0)
        ORDER BY expira_en DESC, id DESC"
    );
    $stmt->execute([':colegio_id' => $colegioId, ':estado' => $estado, ':buscar' => $busqueda]);
    $invitaciones = $stmt->fetchAll();
} catch (RuntimeException $e) {
    http_response_code(500);
    $error = true;
}

function escaparInvitacion(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$zonaHoraria = new DateTimeZone('America/Santiago');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitaciones | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
    <header>
        <a href="mis_aulas.php" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= escaparInvitacion($_SESSION['nombre'] ?? '') ?></span>
        <nav aria-label="Panel docente">
            <a href="mis_aulas.php" class="nav-link">Mis aulas</a>
            <a href="invitaciones.php" class="nav-link activo" aria-current="page">Invitaciones</a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>
    <main class="panel-contenido">
        <div class="panel-intro">
            <h1>Invitaciones</h1>
            <p class="panel-resumen">Invitaciones del colegio</p>
        </div>

        <?php if ($error): ?>
            <p class="curso panel-aviso panel-aviso-error" role="alert">No se pudieron cargar las invitaciones. Inténtalo nuevamente.</p>
        <?php else: ?>
            <form method="get" class="curso panel-asignacion panel-filtros-invitaciones" aria-label="Filtrar invitaciones">
                <div>
                    <label for="buscar-invitacion">Buscar por usuario, correo o RUT</label>
                    <input id="buscar-invitacion" name="buscar" type="search" value="<?= escaparInvitacion($busqueda) ?>">
                </div>
                <div>
                    <label for="estado-invitacion">Estado</label>
                    <select id="estado-invitacion" name="estado">
                        <option value="">Todos</option>
                        <?php foreach ($estados as $opcion): ?>
                            <option value="<?= escaparInvitacion($opcion) ?>"<?= $opcion === $estado ? ' selected' : '' ?>>
                                <?= escaparInvitacion($opcion) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="panel-boton">Filtrar</button>
            </form>

            <?php if (!$invitaciones): ?>
                <p class="curso panel-aviso">
                    <?= $busqueda !== '' || $estado !== ''
                        ? 'No se encontraron invitaciones con esos filtros.'
                        : 'Este colegio todavía no tiene invitaciones.' ?>
                </p>
            <?php else: ?>
                <ul class="panel-tarjetas panel-listado-invitaciones" role="list" aria-label="Invitaciones del colegio">
                    <?php foreach ($invitaciones as $invitacion): ?>
                        <?php
                        $expira = (new DateTimeImmutable($invitacion['expira_en']))->setTimezone($zonaHoraria);
                        ?>
                        <li class="curso">
                            <h2><?= escaparInvitacion($invitacion['username']) ?></h2>
                            <dl>
                                <dt>Correo</dt>
                                <dd><?= escaparInvitacion($invitacion['email']) ?></dd>
                                <dt>RUT</dt>
                                <dd><?= escaparInvitacion($invitacion['rut']) ?></dd>
                                <dt>Expira</dt>
                                <dd><time datetime="<?= escaparInvitacion($expira->format(DATE_ATOM)) ?>"><?= escaparInvitacion($expira->format('d/m/Y H:i')) ?></time></dd>
                                <dt>Estado</dt>
                                <dd><strong><?= escaparInvitacion($invitacion['estado']) ?></strong></dd>
                            </dl>
                            <?php if ($invitacion['estado'] === 'Pendiente'): ?>
                                <p>El enlace solo está disponible al crear la invitación.</p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
