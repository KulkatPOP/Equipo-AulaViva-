<?php

require_once __DIR__ . '/rol.php';

$rolMateriales = $_SESSION['rol'];

if (!in_array($rolMateriales, ['alumno', 'profesor'], true)) {
    http_response_code(403);
    exit('No tienes permiso para acceder a los materiales.');
}

require_once __DIR__ . '/funciones_materiales.php';