<?php

require_once __DIR__ . '/rol.php';

$rolMateriales = $_SESSION['rol'];
if (!in_array($rolMateriales, ['alumno', 'profesor'], true)) {
    http_response_code(403);
    exit('No tienes permiso para acceder a los materiales.');
}

// La misma comprobación se usa al listar, guardar y entregar archivos.
function consultaAulasMateriales(bool $esProfesor): string
{
    $relacion = $esProfesor
        ? 'JOIN aula_profesores AS vinculo ON vinculo.aula_id = a.id AND vinculo.profesor_id = u.id'
        : 'JOIN aula_alumnos AS vinculo ON vinculo.aula_id = a.id AND vinculo.alumno_id = u.id';
    return "SELECT a.id, a.nombre
            FROM aulas AS a
            JOIN usuarios AS u ON u.colegio_id = a.colegio_id
            $relacion
            WHERE u.id = :usuario_id
              AND u.colegio_id = :colegio_id
              AND u.rol = :rol
              AND u.activo = TRUE
              AND a.activo = TRUE";
}

function parametrosAulasMateriales(): array
{
    return [
        ':usuario_id' => (int) $_SESSION['usuario_id'],
        ':colegio_id' => (int) $_SESSION['colegio_id'],
        ':rol' => $_SESSION['rol'],
    ];
}

function escaparMaterial(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
