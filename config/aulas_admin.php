<?php

declare(strict_types=1);


/* Retirar integrantes del aula */

function retirarIntegranteAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId,
    int $usuarioId,
    string $rol
): void {

    if (!$pdo->inTransaction()) {
        throw new LogicException(
            'La operación requiere una transacción.'
        );
    }

    $relacion = match ($rol) {
        'profesor' => ['aula_profesores', 'profesor_id'],
        'alumno' => ['aula_alumnos', 'alumno_id'],
        default => throw new InvalidArgumentException(
            'Rol no válido.'
        )
    };

    [$tabla, $columna] = $relacion;

    // Bloquear el aula durante la operación.

    $stmt = $pdo->prepare("
        SELECT id, activo
        FROM aulas
        WHERE id = ?
          AND colegio_id = ?
        FOR UPDATE
    ");

    $stmt->execute([$aulaId, $colegioId]);

    $aula = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$aula) {
        throw new InvalidArgumentException(
            'Aula no disponible.'
        );
    }

    // Verificar y bloquear el vínculo.

    $stmt = $pdo->prepare("
        SELECT u.id
        FROM $tabla v
        JOIN usuarios u ON u.id = v.$columna
        WHERE v.aula_id = ?
          AND u.id = ?
          AND u.colegio_id = ?
          AND u.rol = ?
        FOR UPDATE OF v
        FOR SHARE OF u
    ");

    $stmt->execute([
        $aulaId,
        $usuarioId,
        $colegioId,
        $rol
    ]);

    if ($stmt->fetchColumn() === false) {
        throw new InvalidArgumentException(
            'El integrante no pertenece al aula.'
        );
    }

    // Proteger la continuidad del aula.

    if (
        $rol === 'profesor' &&
        aulaAdminActiva($aula['activo'])
    ) {

        $stmt = $pdo->prepare("
            SELECT u.id
            FROM aula_profesores ap
            JOIN usuarios u ON u.id = ap.profesor_id
            WHERE ap.aula_id = ?
              AND u.colegio_id = ?
              AND u.rol = 'profesor'
              AND u.activo = TRUE
              AND u.id <> ?
            FOR SHARE OF u
        ");

        $stmt->execute([
            $aulaId,
            $colegioId,
            $usuarioId
        ]);

        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException(
                'El aula debe conservar al menos un profesor activo.'
            );
        }
    }

    // Proteger el historial académico del estudiante.

    if ($rol === 'alumno') {

        $stmt = $pdo->prepare("
            SELECT CASE WHEN

                EXISTS (
                    SELECT 1
                    FROM entregas e
                    JOIN evaluaciones ev
                      ON ev.id = e.evaluacion_id
                    WHERE ev.aula_id = ?
                      AND e.estudiante_id = ?
                )

                OR EXISTS (
                    SELECT 1
                    FROM notas_manuales
                    WHERE aula_id = ?
                      AND estudiante_id = ?
                )

                OR EXISTS (
                    SELECT 1
                    FROM ajustes_notas
                    WHERE aula_id = ?
                      AND estudiante_id = ?
                )

            THEN 1 ELSE 0 END
        ");

        $stmt->execute([
            $aulaId,
            $usuarioId,
            $aulaId,
            $usuarioId,
            $aulaId,
            $usuarioId
        ]);

        if ((int) $stmt->fetchColumn() === 1) {
            throw new InvalidArgumentException(
                'No puedes retirar al estudiante porque tiene historial académico en esta aula.'
            );
        }
    }

    // Eliminar únicamente el vínculo.

    $stmt = $pdo->prepare("
        DELETE FROM $tabla
        WHERE aula_id = ?
          AND $columna = ?
        RETURNING aula_id
    ");

    $stmt->execute([
        $aulaId,
        $usuarioId
    ]);

    if ($stmt->fetchColumn() === false) {
        throw new InvalidArgumentException(
            'No se pudo retirar al integrante.'
        );
    }
}


/* Usuarios disponibles para incorporar al aula */

function usuariosDisponiblesAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId,
    string $rol
): array {
    $relacion = match ($rol) {
        'profesor' => ['aula_profesores', 'profesor_id'],
        'alumno' => ['aula_alumnos', 'alumno_id'],
        default => throw new InvalidArgumentException('Rol no válido.')
    };

    [$tabla, $columna] = $relacion;

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.username
        FROM usuarios u
        JOIN aulas a ON a.colegio_id = u.colegio_id
        WHERE a.id = ?
          AND a.colegio_id = ?
          AND a.activo = TRUE
          AND u.rol = ?
          AND u.activo = TRUE
          AND NOT EXISTS (
              SELECT 1
              FROM $tabla v
              WHERE v.aula_id = a.id
                AND v.$columna = u.id
          )
        ORDER BY u.nombre, u.apellido, u.id
    ");

    $stmt->execute([
        $aulaId,
        $colegioId,
        $rol
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Incorporar integrante */

function agregarIntegranteAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId,
    int $usuarioId,
    string $rol
): bool {
    $relacion = match ($rol) {
        'profesor' => ['aula_profesores', 'profesor_id'],
        'alumno' => ['aula_alumnos', 'alumno_id'],
        default => throw new InvalidArgumentException('Rol no válido.')
    };

    [$tabla, $columna] = $relacion;

    $stmt = $pdo->prepare("
        WITH autorizados AS (
            SELECT
                a.id AS aula_id,
                u.id AS usuario_id
            FROM aulas a
            JOIN usuarios u
              ON u.colegio_id = a.colegio_id
            WHERE a.id = ?
              AND a.colegio_id = ?
              AND a.activo = TRUE
              AND u.id = ?
              AND u.rol = ?
              AND u.activo = TRUE
            FOR SHARE OF a, u
        )
        INSERT INTO $tabla (aula_id, $columna)
        SELECT aula_id, usuario_id
        FROM autorizados
        ON CONFLICT (aula_id, $columna)
        DO NOTHING
        RETURNING aula_id
    ");

    $stmt->execute([
        $aulaId,
        $colegioId,
        $usuarioId,
        $rol
    ]);

    return $stmt->fetchColumn() !== false;
}


/* Consultar aulas del establecimiento */

function listarAulasAdmin(PDO $pdo, int $colegioId): array
{
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.nombre,
            a.asignatura_nombre,
            a.activo,
            (
                SELECT COUNT(*)
                FROM aula_profesores ap
                JOIN usuarios u ON u.id = ap.profesor_id
                WHERE ap.aula_id = a.id
                  AND u.colegio_id = a.colegio_id
                  AND u.rol = 'profesor'
            ) AS profesores,
            (
                SELECT COUNT(*)
                FROM aula_alumnos aa
                JOIN usuarios u ON u.id = aa.alumno_id
                WHERE aa.aula_id = a.id
                  AND u.colegio_id = a.colegio_id
                  AND u.rol = 'alumno'
            ) AS estudiantes
        FROM aulas a
        WHERE a.colegio_id = ?
        ORDER BY a.activo DESC, a.nombre, a.asignatura_nombre, a.id
    ");

    $stmt->execute([$colegioId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Verificar autorización administrativa */

function autorizarGestionAulas(
    PDO $pdo,
    int $adminId,
    int $colegioId
): bool {
    $stmt = $pdo->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND colegio_id = ?
          AND rol = 'admin'
          AND activo = TRUE
        FOR SHARE
    ");

    $stmt->execute([$adminId, $colegioId]);

    return $stmt->fetchColumn() !== false;
}

/* Validar nombres */

function validarNombreAula(mixed $valor): string
{
    if (!is_string($valor)) {
        throw new InvalidArgumentException(
            'El nombre no es válido.'
        );
    }

    $valor = trim($valor);

    if ($valor === '' || strlen($valor) > 120) {
        throw new InvalidArgumentException(
            'Completa los nombres con un máximo de 120 bytes.'
        );
    }

    return $valor;
}

/* Crear aula */

function crearAulaAdmin(
    PDO $pdo,
    int $colegioId,
    string $nombre,
    string $asignatura
): int {
    $stmt = $pdo->prepare("
        INSERT INTO aulas (
            colegio_id,
            nombre,
            asignatura_nombre,
            activo
        )
        VALUES (?, ?, ?, TRUE)
        RETURNING id
    ");

    $stmt->execute([
        $colegioId,
        validarNombreAula($nombre),
        validarNombreAula($asignatura)
    ]);

    return (int) $stmt->fetchColumn();
}

/* Consultar aula individual */

function obtenerAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId
): ?array {
    $stmt = $pdo->prepare("
        SELECT
            id,
            nombre,
            asignatura_nombre,
            activo
        FROM aulas
        WHERE id = ?
          AND colegio_id = ?
    ");

    $stmt->execute([$aulaId, $colegioId]);

    $aula = $stmt->fetch(PDO::FETCH_ASSOC);

    return $aula ?: null;
}

/* Editar aula */

function editarAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId,
    string $nombre,
    string $asignatura
): bool {
    $stmt = $pdo->prepare("
        UPDATE aulas
        SET nombre = ?,
            asignatura_nombre = ?
        WHERE id = ?
          AND colegio_id = ?
        RETURNING id
    ");

    $stmt->execute([
        validarNombreAula($nombre),
        validarNombreAula($asignatura),
        $aulaId,
        $colegioId
    ]);

    return $stmt->fetchColumn() !== false;
}

/* Activar o desactivar aula */

function cambiarEstadoAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId,
    bool $activo
): bool {
    $stmt = $pdo->prepare("
        UPDATE aulas
        SET activo = CAST(? AS boolean)
        WHERE id = ?
          AND colegio_id = ?
        RETURNING id
    ");

    $stmt->execute([
        $activo ? 'true' : 'false',
        $aulaId,
        $colegioId
    ]);

    return $stmt->fetchColumn() !== false;
}

/* Consultar integrantes */

function integrantesAulaAdmin(
    PDO $pdo,
    int $colegioId,
    int $aulaId
): array {
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.username,
            u.rol,
            u.activo
        FROM aulas a
        JOIN aula_profesores ap ON ap.aula_id = a.id
        JOIN usuarios u ON u.id = ap.profesor_id
        WHERE a.id = ?
          AND a.colegio_id = ?
          AND u.colegio_id = a.colegio_id
          AND u.rol = 'profesor'

        UNION ALL

        SELECT
            u.id,
            u.nombre,
            u.apellido,
            u.username,
            u.rol,
            u.activo
        FROM aulas a
        JOIN aula_alumnos aa ON aa.aula_id = a.id
        JOIN usuarios u ON u.id = aa.alumno_id
        WHERE a.id = ?
          AND a.colegio_id = ?
          AND u.colegio_id = a.colegio_id
          AND u.rol = 'alumno'

        ORDER BY rol, nombre, apellido
    ");

    $stmt->execute([
        $aulaId,
        $colegioId,
        $aulaId,
        $colegioId
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Interpretar valores booleanos de PostgreSQL */

function aulaAdminActiva(mixed $valor): bool
{
    return in_array(
        $valor,
        [true, 1, '1', 't', 'true'],
        true
    );
}
