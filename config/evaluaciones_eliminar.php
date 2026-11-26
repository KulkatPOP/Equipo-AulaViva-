<?php

require_once __DIR__ . '/evaluaciones.php';

// Eliminar evaluación

function eliminarEvaluacion(
    PDO $pdo,
    int $aulaId,
    string $evaluacionId
): void {

    exigirRol('profesor');

    $pdo->beginTransaction();

    try {

        // Verificar acceso al aula

        if (!aulaEvaluaciones($pdo, $aulaId, true)) {
            throw new DomainException(
                'No tienes permiso para administrar esta aula.'
            );
        }

        // Bloquear modificaciones simultáneas del libro de notas

        $stmt = $pdo->prepare(
            'SELECT pg_advisory_xact_lock(731007, ?)'
        );

        $stmt->execute([$aulaId]);

        // Bloquear evaluación

        $stmt = $pdo->prepare('
            SELECT id
            FROM evaluaciones
            WHERE id = ?
                AND aula_id = ?
            FOR UPDATE
        ');

        $stmt->execute([
            $evaluacionId,
            $aulaId
        ]);

        if (!$stmt->fetch()) {
            throw new DomainException(
                'La evaluación no existe o no tienes permiso para eliminarla.'
            );
        }

        // Comprobar intentos registrados

        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM entregas
            WHERE evaluacion_id = ?
        ');

        $stmt->execute([$evaluacionId]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new DomainException(
                'No puedes eliminar una evaluación que tiene intentos registrados.'
            );
        }

        // Consultar columna automática del libro de notas

        $stmt = $pdo->prepare('
            SELECT id
            FROM libro_notas
            WHERE evaluacion_id = ?
                AND aula_id = ?
                AND tipo = ?
            FOR UPDATE
        ');

        $stmt->execute([
            $evaluacionId,
            $aulaId,
            'automatica'
        ]);

        $columna = $stmt->fetch();

        if ($columna) {

            // Proteger calificaciones asociadas

            $stmt = $pdo->prepare('
                SELECT
                    (SELECT COUNT(*)
                     FROM ajustes_notas
                     WHERE libro_nota_id = ?)
                    +
                    (SELECT COUNT(*)
                     FROM notas_manuales
                     WHERE libro_nota_id = ?)
            ');

            $stmt->execute([
                $columna['id'],
                $columna['id']
            ]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new DomainException(
                    'La evaluación tiene calificaciones asociadas.'
                );
            }

            // Comprobar cantidad de columnas

            $stmt = $pdo->prepare('
                SELECT COUNT(*)
                FROM libro_notas
                WHERE aula_id = ?
            ');

            $stmt->execute([$aulaId]);

            $cantidad = (int) $stmt->fetchColumn();

            // Eliminar columna automática vacía

            $stmt = $pdo->prepare('
                DELETE FROM libro_notas
                WHERE id = ?
                    AND aula_id = ?
                    AND evaluacion_id = ?
            ');

            $stmt->execute([
                $columna['id'],
                $aulaId,
                $evaluacionId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'No se pudo actualizar el libro de notas.'
                );
            }

            $cantidad--;

            // Mantener un mínimo de tres columnas

            while ($cantidad < 3) {

                $stmt = $pdo->prepare('
                    INSERT INTO libro_notas (
                        aula_id,
                        numero,
                        titulo,
                        tipo,
                        creado_por
                    )
                    SELECT
                        ?,
                        COALESCE(MAX(numero), 0) + 1,
                        ?,
                        ?,
                        ?
                    FROM libro_notas
                    WHERE aula_id = ?
                ');

                $stmt->execute([
                    $aulaId,
                    'Nota manual',
                    'manual',
                    (int) $_SESSION['usuario_id'],
                    $aulaId
                ]);

                $cantidad++;
            }
        }

        // Eliminar evaluación

        $stmt = $pdo->prepare('
            DELETE FROM evaluaciones
            WHERE id = ?
                AND aula_id = ?
                AND NOT EXISTS (
                    SELECT 1
                    FROM entregas
                    WHERE evaluacion_id = ?
                )
        ');

        $stmt->execute([
            $evaluacionId,
            $aulaId,
            $evaluacionId
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new DomainException(
                'No se pudo eliminar la evaluación.'
            );
        }

        $pdo->commit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}