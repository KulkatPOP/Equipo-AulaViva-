<?php

function consultaAulasMateriales(bool $esProfesor): string
{
    $relacion = $esProfesor
        ? 'JOIN aula_profesores AS vinculo ON vinculo.aula_id = a.id AND vinculo.profesor_id = u.id'
        : 'JOIN aula_alumnos AS vinculo ON vinculo.aula_id = a.id AND vinculo.alumno_id = u.id';

    return "SELECT a.id, a.nombre, a.asignatura_nombre
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
    return htmlspecialchars(
        $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}