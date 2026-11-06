<?php

$rol = trim($_POST['rol']);
// ruleid: aulaviva-php-request-to-session-role
$_SESSION['rol'] = $rol;

$datos = json_decode(file_get_contents('php://input'), true);
// ruleid: aulaviva-php-request-to-session-role
$_SESSION['rol'] = $datos['rol'];

// ok: aulaviva-php-request-to-session-role
$_SESSION['rol'] = $usuario['rol'];