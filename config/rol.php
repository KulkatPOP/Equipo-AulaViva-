<?php

/* VERIFICAR QUE EXISTA UNA SESIÓN AUTENTICADA */

require_once __DIR__ . '/sesion.php';

/* FUNCIÓN PARA EXIGIR UN ROL */

function exigirRol(string $rol): void
{
    $rolesPermitidos = ['alumno', 'profesor', 'admin'];

    if (!in_array($rol, $rolesPermitidos, true)) {
        http_response_code(500);
        exit('Configuración de rol inválida.');
    }

    if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== $rol) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta página.');
    }
}