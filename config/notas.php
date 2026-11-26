<?php

/* VALIDAR CALIFICACIÓN MANUAL */

function validarNotaManual(mixed $valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }

    // Rechazar caracteres de control.
    if (preg_match('/[\x00-\x1F\x7F]/', $valor)) {
        return null;
    }

    $valor = str_replace(',', '.', trim($valor));

    if (!preg_match('/\A(?:[1-6](?:\.[0-9])?|7(?:\.0)?)\z/', $valor)) {
        return null;
    }

    return number_format((float) $valor, 1, '.', '');
}

/* FORMATEAR CALIFICACIÓN */

function formatearNota(?float $nota): string
{
    return $nota === null
        ? '—'
        : number_format($nota, 1, ',', '');
}

/* BLOQUEAR OPERACIONES SIMULTÁNEAS DEL LIBRO */

function bloquearLibroNotas(PDO $pdo, int $aulaId): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Se requiere una transacción activa.');
    }

    $stmt = $pdo->prepare(
        'SELECT pg_advisory_xact_lock(731007, CAST(? AS INTEGER))'
    );

    $stmt->execute([$aulaId]);
    $stmt->closeCursor();
}

/* OBTENER COLUMNAS DEL LIBRO */

function obtenerColumnasNotas(PDO $pdo, int $aulaId): array
{
    $stmt = $pdo->prepare(
        'SELECT ln.id,
                ln.numero,
                ln.titulo,
                ln.tipo,
                ln.evaluacion_id,
                ev.titulo AS evaluacion_titulo
         FROM libro_notas AS ln
         LEFT JOIN evaluaciones AS ev
             ON ev.id = ln.evaluacion_id
            AND ev.aula_id = ln.aula_id
         WHERE ln.aula_id = ?
         ORDER BY ln.numero ASC'
    );

    $stmt->execute([$aulaId]);

    $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* ENUMERAR COLUMNAS CONSECUTIVAMENTE */

    foreach ($columnas as $indice => &$columna) {

        $columna['titulo'] = 'Nota ' . ($indice + 1);

    }

    unset($columna);

    return $columnas;
}

/* CREAR UNA NUEVA COLUMNA */

function crearColumnaNota(
    PDO $pdo,
    int $aulaId,
    int $profesorId,
    ?string $evaluacionId = null
): void {
    bloquearLibroNotas($pdo, $aulaId);

    if ($evaluacionId !== null) {

        $stmt = $pdo->prepare(
            'SELECT id
             FROM evaluaciones
             WHERE id = ?
               AND aula_id = ?
               AND publicada_en IS NOT NULL
               AND publicada_en <= CURRENT_TIMESTAMP'
        );

        $stmt->execute([$evaluacionId, $aulaId]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException(
                'Evaluación no disponible.'
            );
        }
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(numero), 0) + 1
         FROM libro_notas
         WHERE aula_id = ?'
    );

    $stmt->execute([$aulaId]);

    $numero = (int) $stmt->fetchColumn();

    $tipo = $evaluacionId === null
        ? 'manual'
        : 'automatica';

    $stmt = $pdo->prepare(
        'INSERT INTO libro_notas (
            aula_id,
            numero,
            titulo,
            tipo,
            evaluacion_id,
            creado_por
         )
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (evaluacion_id) DO NOTHING'
    );

    $stmt->execute([
        $aulaId,
        $numero,
        'Nota ' . $numero,
        $tipo,
        $evaluacionId,
        $profesorId
    ]);
}

/* SINCRONIZAR EVALUACIONES DIGITALES */

function sincronizarNotasAutomaticas(
    PDO $pdo,
    int $aulaId,
    int $profesorId
): void {
    bloquearLibroNotas($pdo, $aulaId);

    $stmt = $pdo->prepare(
        'SELECT ev.id
         FROM evaluaciones AS ev
         WHERE ev.aula_id = ?
           AND ev.publicada_en IS NOT NULL
           AND ev.publicada_en <= CURRENT_TIMESTAMP
           AND NOT EXISTS (
               SELECT 1
               FROM libro_notas AS ln
               WHERE ln.evaluacion_id = ev.id
           )
         ORDER BY ev.publicada_en ASC, ev.id ASC'
    );

    $stmt->execute([$aulaId]);

    $evaluaciones = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($evaluaciones as $evaluacionId) {

        crearColumnaNota(
            $pdo,
            $aulaId,
            $profesorId,
            $evaluacionId
        );
    }
}

/* GARANTIZAR CUATRO COLUMNAS INICIALES */

function inicializarLibroNotas(
    PDO $pdo,
    int $aulaId,
    int $profesorId
): void {
    bloquearLibroNotas($pdo, $aulaId);

    sincronizarNotasAutomaticas(
        $pdo,
        $aulaId,
        $profesorId
    );

    $columnas = obtenerColumnasNotas($pdo, $aulaId);

    for ($i = count($columnas); $i < 3; $i++) {

        crearColumnaNota(
            $pdo,
            $aulaId,
            $profesorId
        );
    }
}

/* OBTENER NOTAS MANUALES Y AUTOMÁTICAS */

function obtenerCalificacionesLibro(
    PDO $pdo,
    int $aulaId,
    int $colegioId
): array {

    $stmt = $pdo->prepare(
        "SELECT ln.id AS columna_id,
                nm.estudiante_id,
                nm.calificacion
         FROM libro_notas AS ln
         INNER JOIN notas_manuales AS nm
             ON nm.libro_nota_id = ln.id
            AND nm.aula_id = ln.aula_id
         INNER JOIN aula_alumnos AS aa
             ON aa.aula_id = ln.aula_id
            AND aa.alumno_id = nm.estudiante_id
         INNER JOIN usuarios AS u
             ON u.id = nm.estudiante_id
         WHERE ln.aula_id = :aula_manual
           AND u.colegio_id = :colegio_manual
           AND u.rol = 'alumno'
           AND u.activo = TRUE
           AND ln.tipo = 'manual'

         UNION ALL

         SELECT ln.id AS columna_id,
                en.estudiante_id,
                COALESCE(an.calificacion, en.calificacion) AS calificacion
         FROM libro_notas AS ln
         INNER JOIN evaluaciones AS ev
             ON ev.id = ln.evaluacion_id
            AND ev.aula_id = ln.aula_id
         INNER JOIN entregas AS en
             ON en.evaluacion_id = ev.id
         LEFT JOIN ajustes_notas AS an
             ON an.libro_nota_id = ln.id
            AND an.aula_id = ln.aula_id
            AND an.estudiante_id = en.estudiante_id
         INNER JOIN aula_alumnos AS aa
             ON aa.aula_id = ln.aula_id
            AND aa.alumno_id = en.estudiante_id
         INNER JOIN usuarios AS u
             ON u.id = en.estudiante_id
         WHERE ln.aula_id = :aula_automatica
           AND u.colegio_id = :colegio_automatico
           AND u.rol = 'alumno'
           AND u.activo = TRUE
           AND ln.tipo = 'automatica'
           AND ev.publicada_en IS NOT NULL
           AND ev.publicada_en <= CURRENT_TIMESTAMP
           AND en.estado = 'calificada'
           AND en.calificacion IS NOT NULL"
    );

    $stmt->execute([
        ':aula_manual' => $aulaId,
        ':colegio_manual' => $colegioId,
        ':aula_automatica' => $aulaId,
        ':colegio_automatico' => $colegioId
    ]);

    $notas = [];

    foreach ($stmt as $fila) {
        $notas[(int) $fila['estudiante_id']][(int) $fila['columna_id']] =
            (float) $fila['calificacion'];
    }

    return $notas;
}

/* GUARDAR O ACTUALIZAR UNA NOTA MANUAL */

function guardarNotaManual(
    PDO $pdo,
    int $aulaId,
    int $colegioId,
    int $profesorId,
    int $columnaId,
    int $estudianteId,
    mixed $valor
): void {
    if (!$pdo->inTransaction()) {
        throw new LogicException(
            'Se requiere una transacción activa.'
        );
    }

    $nota = validarNotaManual($valor);

    if ($nota === null) {
        throw new InvalidArgumentException(
            'La nota debe estar entre 1,0 y 7,0.'
        );
    }

    $stmt = $pdo->prepare(
        "SELECT ln.id
         FROM libro_notas AS ln
         INNER JOIN aula_alumnos AS aa
             ON aa.aula_id = ln.aula_id
         INNER JOIN usuarios AS u
             ON u.id = aa.alumno_id
         INNER JOIN aulas AS a
             ON a.id = ln.aula_id
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         INNER JOIN usuarios AS p
             ON p.id = ap.profesor_id
         WHERE ln.id = ?
           AND ln.aula_id = ?
           AND ln.tipo = 'manual'
           AND aa.alumno_id = ?
           AND u.colegio_id = ?
           AND a.colegio_id = u.colegio_id
           AND a.activo = TRUE
           AND u.rol = 'alumno'
           AND u.activo = TRUE
           AND ap.profesor_id = ?
           AND p.colegio_id = a.colegio_id
           AND p.rol = 'profesor'
           AND p.activo = TRUE
         FOR SHARE OF a, aa, ap, u, p"
    );

    $stmt->execute([
        $columnaId,
        $aulaId,
        $estudianteId,
        $colegioId,
        $profesorId
    ]);

    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException(
            'No tienes permiso para modificar esta nota.'
        );
    }

    $stmt->closeCursor();

    $stmt = $pdo->prepare(
        'INSERT INTO notas_manuales (
            libro_nota_id,
            aula_id,
            estudiante_id,
            calificacion,
            registrado_por,
            actualizado_por
         )
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (libro_nota_id, estudiante_id)
         DO UPDATE SET
            calificacion = EXCLUDED.calificacion,
            actualizado_por = EXCLUDED.actualizado_por,
            actualizado_en = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        $columnaId,
        $aulaId,
        $estudianteId,
        $nota,
        $profesorId,
        $profesorId
    ]);
}

/* CALCULAR PROMEDIO */

function calcularPromedioNotas(array $notas): ?float
{
    if (!$notas) {
        return null;
    }

    return array_sum($notas) / count($notas);
}

/* ELIMINAR COLUMNA DE NOTA MANUAL */

function eliminarColumnaNota(
    PDO $pdo,
    int $aulaId,
    int $columnaId
): void {

    bloquearLibroNotas($pdo, $aulaId);

    /* COMPROBAR MÍNIMO DE TRES COLUMNAS */

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM libro_notas
         WHERE aula_id = ?'
    );

    $stmt->execute([$aulaId]);

    $total = (int) $stmt->fetchColumn();

    if ($total <= 3) {
        throw new InvalidArgumentException(
            'El libro debe mantener un mínimo de 3 notas.'
        );
    }

    /* ELIMINAR SOLAMENTE COLUMNAS MANUALES VACÍAS */

    $stmt = $pdo->prepare(
        "DELETE FROM libro_notas AS ln
         WHERE ln.id = ?
           AND ln.aula_id = ?
           AND ln.tipo = 'manual'
           AND NOT EXISTS (
               SELECT 1
               FROM notas_manuales AS nm
               WHERE nm.libro_nota_id = ln.id
           )"
    );

    $stmt->execute([
        $columnaId,
        $aulaId
    ]);

    if ($stmt->rowCount() !== 1) {

        throw new InvalidArgumentException(
            'Solo puedes eliminar notas manuales sin calificaciones registradas.'
        );

    }
}
/* GUARDAR CALIFICACIONES MANUALES Y AUTOMÁTICAS */

function guardarCalificacionLibro(
    PDO $pdo,
    int $aulaId,
    int $colegioId,
    int $profesorId,
    int $columnaId,
    int $estudianteId,
    mixed $valor
): void {

    bloquearLibroNotas($pdo, $aulaId);

    $nota = validarNotaManual($valor);

    if ($nota === null) {
        throw new InvalidArgumentException(
            'La nota debe estar entre 1,0 y 7,0.'
        );
    }

    /* VALIDAR PROFESOR, ESTUDIANTE Y COLUMNA */

    $stmt = $pdo->prepare(
        "SELECT ln.tipo, ln.evaluacion_id
         FROM libro_notas AS ln
         INNER JOIN aulas AS a
             ON a.id = ln.aula_id
         INNER JOIN aula_profesores AS ap
             ON ap.aula_id = a.id
         INNER JOIN usuarios AS p
             ON p.id = ap.profesor_id
         INNER JOIN aula_alumnos AS aa
             ON aa.aula_id = a.id
         INNER JOIN usuarios AS u
             ON u.id = aa.alumno_id
         WHERE ln.id = ?
           AND ln.aula_id = ?
           AND a.colegio_id = ?
           AND ap.profesor_id = ?
           AND aa.alumno_id = ?
           AND a.activo = TRUE
           AND p.activo = TRUE
           AND p.rol = 'profesor'
           AND p.colegio_id = a.colegio_id
           AND u.activo = TRUE
           AND u.rol = 'alumno'
           AND u.colegio_id = a.colegio_id
         FOR SHARE OF a, ap, aa, p, u"
    );

    $stmt->execute([
        $columnaId,
        $aulaId,
        $colegioId,
        $profesorId,
        $estudianteId
    ]);

    $columna = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt->closeCursor();

    if (!$columna) {
        throw new InvalidArgumentException(
            'No tienes permiso para modificar esta nota.'
        );
    }

    /* GUARDAR NOTA MANUAL */

    if ($columna['tipo'] === 'manual') {

        guardarNotaManual(
            $pdo,
            $aulaId,
            $colegioId,
            $profesorId,
            $columnaId,
            $estudianteId,
            $nota
        );

        return;
    }

    /* VALIDAR EVALUACIÓN AUTOMÁTICA */

    if ($columna['tipo'] !== 'automatica') {
        throw new InvalidArgumentException(
            'Tipo de nota inválido.'
        );
    }

    $stmt = $pdo->prepare(
        "SELECT en.id
         FROM entregas AS en
         INNER JOIN evaluaciones AS ev
             ON ev.id = en.evaluacion_id
         WHERE en.evaluacion_id = ?
           AND en.estudiante_id = ?
           AND ev.aula_id = ?
           AND ev.publicada_en IS NOT NULL
           AND ev.publicada_en <= CURRENT_TIMESTAMP
           AND en.estado = 'calificada'
           AND en.calificacion IS NOT NULL"
    );

    $stmt->execute([
        $columna['evaluacion_id'],
        $estudianteId,
        $aulaId
    ]);

    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException(
            'El estudiante todavía no tiene una evaluación calificada.'
        );
    }

    $stmt->closeCursor();

    /* GUARDAR AJUSTE DE NOTA AUTOMÁTICA */

    $stmt = $pdo->prepare(
        'INSERT INTO ajustes_notas (
            libro_nota_id,
            aula_id,
            estudiante_id,
            calificacion,
            registrado_por,
            actualizado_por
         )
         VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (libro_nota_id, estudiante_id)
         DO UPDATE SET
            calificacion = EXCLUDED.calificacion,
            actualizado_por = EXCLUDED.actualizado_por,
            actualizado_en = CURRENT_TIMESTAMP'
    );

    $stmt->execute([
        $columnaId,
        $aulaId,
        $estudianteId,
        $nota,
        $profesorId,
        $profesorId
    ]);
}