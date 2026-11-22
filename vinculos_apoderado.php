<?php
require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';
exigirRol('admin');
header('Cache-Control: private, no-store');
if (!isset($_SESSION['csrf_apoderados']) || !is_string($_SESSION['csrf_apoderados'])) {
    $_SESSION['csrf_apoderados'] = bin2hex(random_bytes(32));
}
$error = '';
$usuarios = [];
$vinculos = [];
try {
    require __DIR__ . '/config/conexion.php';
    $admin = consultaApoderados($pdo, "SELECT id FROM usuarios WHERE id = ? AND colegio_id = ? AND rol = 'admin' AND activo = TRUE", [(int) $_SESSION['usuario_id'], (int) $_SESSION['colegio_id']])->fetchColumn();
    if ($admin === false) { http_response_code(403); exit('No tienes permiso para acceder a esta página.'); }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_apoderados'], $_POST['csrf_token'])) {
            http_response_code(403);
            $error = 'Solicitud no válida. Recarga la página e inténtalo nuevamente.';
        } else {
            $apoderado = idApoderados($_POST['apoderado_id'] ?? null);
            $estudiante = idApoderados($_POST['estudiante_id'] ?? null);
            if ($apoderado === null || $estudiante === null) {
                http_response_code(400);
                $error = 'Selecciona un apoderado y un estudiante válidos.';
            } elseif (confirmarVinculoApoderado($pdo, (int) $admin, (int) $_SESSION['colegio_id'], $apoderado, $estudiante)) {
                $_SESSION['aviso_apoderados'] = 'Vinculación confirmada.';
                header('Location: vinculos_apoderado.php', true, 303);
                exit;
            } else {
                $error = 'No se pudo confirmar la vinculación o ya estaba confirmada.';
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET, POST'); http_response_code(405); exit('Método no permitido.');
    }
    $usuarios = consultaApoderados($pdo, "SELECT id, nombre, apellido, username, rol FROM usuarios WHERE colegio_id = ? AND activo = TRUE AND rol IN ('apoderado', 'alumno') ORDER BY apellido, nombre, id", [(int) $_SESSION['colegio_id']])->fetchAll(PDO::FETCH_ASSOC);
    $vinculos = consultaApoderados($pdo, "
        SELECT v.apoderado_id, v.estudiante_id, v.estado, v.confirmada_en,
               p.nombre AS apoderado_nombre, p.apellido AS apoderado_apellido,
               u.nombre AS estudiante_nombre, u.apellido AS estudiante_apellido,
               (p.activo AND p.rol = 'apoderado' AND u.activo AND u.rol = 'alumno') AS puede_confirmar
        FROM apoderado_estudiantes v
        JOIN usuarios p ON p.id = v.apoderado_id AND p.colegio_id = v.colegio_id
        JOIN usuarios u ON u.id = v.estudiante_id AND u.colegio_id = v.colegio_id
        JOIN usuarios d ON d.id = ? AND d.colegio_id = v.colegio_id AND d.rol = 'admin' AND d.activo
        WHERE v.colegio_id = ?
        ORDER BY v.estado DESC, p.apellido, p.nombre, u.apellido, u.nombre, v.apoderado_id, v.estudiante_id
    ", [(int) $admin, (int) $_SESSION['colegio_id']])->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    $error = 'No se pudo gestionar la vinculación. Inténtalo nuevamente.';
}
$aviso = $_SESSION['aviso_apoderados'] ?? '';
unset($_SESSION['aviso_apoderados']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vincular estudiantes | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css"><link rel="stylesheet" href="css/apoderados.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente panel-apoderado">
<header><a href="vinculos_apoderado.php" class="logo"><img src="img/Logo.png" alt=""><span class="title-header">Aula Viva</span></a><span class="panel-identidad"><?= escaparApoderados($_SESSION['nombre'] ?? '') ?></span><nav aria-label="Navegación"><a href="logout.php" class="nav-link">Cerrar sesión</a></nav></header>
<main class="panel-contenido">
    <h1>Vincular estudiantes</h1>
    <p>Confirma únicamente asociaciones verificadas por el colegio.</p>
    <?php if ($error !== ''): ?><p class="curso panel-aviso panel-aviso-error" role="alert"><?= escaparApoderados($error) ?></p><?php endif; ?>
    <?php if ($aviso !== ''): ?><p class="curso panel-aviso" role="status"><?= escaparApoderados($aviso) ?></p><?php endif; ?>
    <section aria-labelledby="vinculos-existentes">
        <h2 id="vinculos-existentes">Vinculaciones existentes</h2>
        <?php if (!$vinculos && $error === ''): ?>
            <p class="curso panel-aviso">No hay vinculaciones registradas.</p>
        <?php else: ?>
            <ul class="apoderado-vinculos">
                <?php foreach ($vinculos as $vinculo): ?>
                    <li class="curso apoderado-vinculo">
                        <dl>
                            <div><dt>Apoderado</dt><dd><?= escaparApoderados($vinculo['apoderado_nombre'] . ' ' . $vinculo['apoderado_apellido']) ?></dd></div>
                            <div><dt>Estudiante</dt><dd><?= escaparApoderados($vinculo['estudiante_nombre'] . ' ' . $vinculo['estudiante_apellido']) ?></dd></div>
                            <div><dt>Estado</dt><dd><?= $vinculo['estado'] === 'confirmada' ? 'Confirmada' : 'Pendiente' ?></dd></div>
                            <div><dt>Fecha de confirmación</dt><dd><?= $vinculo['estado'] === 'confirmada' ? escaparApoderados(formatearFechaApoderados($vinculo['confirmada_en'])) : 'Sin confirmar' ?></dd></div>
                        </dl>
                        <?php if ($vinculo['estado'] === 'pendiente' && $vinculo['puede_confirmar']): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= escaparApoderados($_SESSION['csrf_apoderados']) ?>">
                                <input type="hidden" name="apoderado_id" value="<?= (int) $vinculo['apoderado_id'] ?>">
                                <input type="hidden" name="estudiante_id" value="<?= (int) $vinculo['estudiante_id'] ?>">
                                <button class="panel-boton" type="submit" aria-label="Confirmar vínculo de <?= escaparApoderados($vinculo['estudiante_nombre'] . ' ' . $vinculo['estudiante_apellido']) ?> con <?= escaparApoderados($vinculo['apoderado_nombre'] . ' ' . $vinculo['apoderado_apellido']) ?>">Confirmar</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <h2>Confirmar otra vinculación</h2>
    <form method="post" class="curso apoderado-formulario">
        <input type="hidden" name="csrf_token" value="<?= escaparApoderados($_SESSION['csrf_apoderados']) ?>">
        <?php foreach (['apoderado' => 'Apoderado', 'alumno' => 'Estudiante'] as $rol => $etiqueta): $campo = $rol === 'alumno' ? 'estudiante_id' : 'apoderado_id'; ?>
            <label for="<?= $campo ?>"><?= $etiqueta ?></label>
            <select name="<?= $campo ?>" id="<?= $campo ?>" required>
                <option value="">Seleccionar</option>
                <?php foreach ($usuarios as $usuario): if ($usuario['rol'] !== $rol) { continue; } ?>
                    <option value="<?= (int) $usuario['id'] ?>"><?= escaparApoderados($usuario['nombre'] . ' ' . $usuario['apellido'] . ' (' . $usuario['username'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
        <?php endforeach; ?>
        <button type="submit" class="panel-boton">Confirmar vinculación</button>
    </form>
</main>
</body>
</html>
