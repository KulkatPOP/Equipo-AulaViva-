<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/tutor/IngestaTutor.php';
require_once __DIR__ . '/../config/conexion.php';

const MAX_INTENTOS = 3;
const ESPERA_REINTENTO = 60;
const ESPERA_CONSULTA = 5;

$repositorio = new RepositorioFragmentosTutor($pdo);

$ingesta = new IngestaTutor(
    $repositorio,
    PdfTextExtractor::desdeEntorno(),
    OllamaEmbeddingProvider::desdeEntorno()
);

// Registro de actividad.

function registrarWorker(string $mensaje): void
{
    echo date('Y-m-d H:i:s') . ' | ' . $mensaje . PHP_EOL;
}

// Recuperar procesamientos interrumpidos.

function recuperarIngestas(
    PDO $pdo,
    RepositorioFragmentosTutor $repositorio
): void {
    $stmt = $pdo->query("
        SELECT material_id
        FROM material_ingestas
        WHERE estado = 'procesando'
    ");

    $materiales = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($materiales as $material) {

        $materialId = (int) $material;

        try {

            $repositorio->reservar($materialId);

        } catch (RuntimeException $error) {

            // Otro proceso puede estar trabajando.
            continue;
        }

        try {

            $stmt = $pdo->prepare("
                UPDATE material_ingestas
                SET estado = 'error',
                    error = 'Procesamiento interrumpido.',
                    actualizado_en = CURRENT_TIMESTAMP
                WHERE material_id = ?
                    AND estado = 'procesando'
            ");

            $stmt->execute([$materialId]);

            if ($stmt->rowCount() > 0) {

                registrarWorker(
                    "Material {$materialId} recuperado."
                );
            }

        } finally {

            $repositorio->liberar($materialId);
        }
    }
}

// Buscar materiales disponibles.

function buscarMaterial(PDO $pdo): array|false
{
    $stmt = $pdo->prepare("
        SELECT
            m.id AS material_id,
            MIN(u.id) AS profesor_id,
            mi.intentos

        FROM material_ingestas mi

        JOIN materiales m
            ON m.id = mi.material_id

        JOIN aulas a
            ON a.id = m.aula_id

        JOIN aula_profesores ap
            ON ap.aula_id = a.id

        JOIN usuarios u
            ON u.id = ap.profesor_id
            AND u.colegio_id = a.colegio_id

        WHERE (
            mi.estado = 'pendiente'

            OR (
                mi.estado = 'error'

                AND mi.actualizado_en <=
                    CURRENT_TIMESTAMP -
                    make_interval(secs => CAST(:espera AS integer))
            )
        )

        AND mi.intentos < :max_intentos
        AND a.activo = TRUE
        AND u.activo = TRUE
        AND u.rol = 'profesor'

        GROUP BY
            m.id,
            mi.intentos,
            mi.creado_en

        ORDER BY mi.creado_en, m.id

        LIMIT 1
    ");

    $stmt->execute([
        'espera' => ESPERA_REINTENTO,
        'max_intentos' => MAX_INTENTOS,
    ]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Iniciar worker.

registrarWorker('Worker del Tutor IA iniciado.');

while (true) {

    try {

        recuperarIngestas($pdo, $repositorio);

        $material = buscarMaterial($pdo);

        if (!$material) {

            sleep(ESPERA_CONSULTA);
            continue;
        }

        $materialId = (int) $material['material_id'];

        $profesorId = (int) $material['profesor_id'];

        $intento = (int) $material['intentos'] + 1;

        registrarWorker(
            "Procesando material {$materialId}. " .
            "Intento {$intento}/" . MAX_INTENTOS
        );

        try {

            $resultado = $ingesta->procesar(
                $materialId,
                $profesorId
            );

            registrarWorker(
                json_encode(
                    $resultado,
                    JSON_THROW_ON_ERROR
                )
            );

        } catch (Throwable $error) {

            registrarWorker(
                "Error al procesar material {$materialId}."
            );

            if ($intento >= MAX_INTENTOS) {

                registrarWorker(
                    "Material {$materialId}: límite de intentos alcanzado."
                );

            } else {

                registrarWorker(
                    "Material {$materialId}: podrá reintentarse en " .
                    ESPERA_REINTENTO . " segundos."
                );
            }

            sleep(ESPERA_CONSULTA);
        }

    } catch (PDOException $error) {

        registrarWorker(
            'Error de base de datos. Reiniciando worker.'
        );

        sleep(10);

        exit(1);

    } catch (Throwable $error) {

        registrarWorker(
            'Error en el worker de indexación.'
        );

        sleep(10);
    }
}