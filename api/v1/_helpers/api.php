<?php

/*
|--------------------------------------------------------------------------
| Funciones comunes de Aula Viva API
|--------------------------------------------------------------------------
*/

/**
 * Devuelve un error utilizando RFC 7807.
 */
function responderProblema(
    int $status,
    string $title,
    string $detail,
    string $type = 'about:blank'
): never {

    http_response_code($status);

    header('Content-Type: application/problem+json; charset=utf-8');

    echo json_encode([
        'type' => $type,
        'title' => $title,
        'status' => $status,
        'detail' => $detail,
        'instance' => $_SERVER['REQUEST_URI']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


/**
 * Comprueba que exista una sesión autenticada
 * y que el usuario tenga el rol requerido.
 */
function exigirRolApi(string $rol): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (!isset($_SESSION['usuario_id'], $_SESSION['rol'])) {

        responderProblema(
            401,
            'No autenticado',
            'Debes iniciar sesión para utilizar este recurso.',
            '/errors/no-autenticado'
        );
    }

    if ($_SESSION['rol'] !== $rol) {

        responderProblema(
            403,
            'Acceso denegado',
            'No tienes permisos para utilizar este recurso.',
            '/errors/acceso-denegado'
        );
    }
}