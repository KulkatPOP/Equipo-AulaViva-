<?php

declare(strict_types=1);

require_once __DIR__ . '/Embeddings.php';
require_once __DIR__ . '/PdfTextExtractor.php';

final class RepositorioFragmentosTutor
{
    public function __construct(private PDO $pdo)
    {
    }

    public function materialAutorizado(int $material, int $profesor, bool $bloquear = false): string
    {
        $sql = "SELECT " . ($bloquear ? 'm.id' : "encode(m.contenido, 'base64')") . "
            FROM materiales m
            JOIN aulas a ON a.id = m.aula_id
            JOIN aula_profesores ap ON ap.aula_id = a.id
            JOIN usuarios u ON u.id = ap.profesor_id AND u.colegio_id = a.colegio_id
            WHERE m.id = ? AND u.id = ? AND u.rol = 'profesor' AND u.activo AND a.activo";
        $stmt = $this->pdo->prepare($sql . ($bloquear ? ' FOR SHARE OF m, a, ap, u' : ''));
        $stmt->execute([$material, $profesor]);
        $contenido = $stmt->fetchColumn();
        if ($contenido === false) {
            throw new RuntimeException('Material no disponible para este docente.');
        }
        return (string) $contenido;
    }

    public function reservar(int $material): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT CASE WHEN pg_try_advisory_lock(hashtextextended('tutor-ingesta:' || CAST(? AS text), 0)) THEN 1 ELSE 0 END"
        );
        $stmt->execute([$material]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Ya hay una ingestión en curso para este material.');
        }
    }

    public function liberar(int $material): void
    {
        $this->pdo->prepare(
            "SELECT pg_advisory_unlock(hashtextextended('tutor-ingesta:' || CAST(? AS text), 0))"
        )->execute([$material]);
    }

    public function iniciar(int $material, int $profesor): void
    {
        $this->transaccion($material, $profesor, function () use ($material): void {
            $this->pdo->prepare("
                INSERT INTO material_ingestas (material_id, estado, intentos) VALUES (?, 'procesando', 1)
                ON CONFLICT (material_id) DO UPDATE SET estado = 'procesando', error = NULL,
                    intentos = material_ingestas.intentos + 1, actualizado_en = CURRENT_TIMESTAMP
            ")->execute([$material]);
        });
    }

    public function finalizar(int $material, int $profesor, array $fragmentos): string
    {
        $estado = $fragmentos === [] ? 'sin_texto' : 'procesado';
        $this->transaccion($material, $profesor, function () use ($material, $fragmentos, $estado): void {
            $this->pdo->prepare('DELETE FROM material_fragmentos WHERE material_id = ?')->execute([$material]);
            $stmt = $this->pdo->prepare("
                INSERT INTO material_fragmentos (material_id, orden, pagina, texto, embedding, estado)
                VALUES (?, ?, ?, ?, CAST(? AS vector), 'procesado')
            ");
            foreach ($fragmentos as $fragmento) {
                $stmt->execute([$material, $fragmento['orden'], $fragmento['pagina'], $fragmento['texto'], $fragmento['embedding']]);
            }
            $this->pdo->prepare('
                UPDATE material_ingestas SET estado = ?, actualizado_en = CURRENT_TIMESTAMP WHERE material_id = ?
            ')->execute([$estado, $material]);
        });
        return $estado;
    }

    public function marcarError(int $material, string $mensaje): void
    {
        // La fila de ingestión se creó con autorización; un borrado concurrente la elimina por FK.
        $stmt = $this->pdo->prepare("
            UPDATE material_ingestas SET estado = 'error', error = ?, actualizado_en = CURRENT_TIMESTAMP
            WHERE material_id = ? AND estado = 'procesando'
        ");
        $stmt->execute([$mensaje, $material]);
    }

    private function transaccion(int $material, int $profesor, callable $guardar): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->materialAutorizado($material, $profesor, true);
            $guardar();
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
    }
}

final class IngestaTutor
{
    public function __construct(
        private RepositorioFragmentosTutor $repositorio,
        private ExtractorInterface $extractor,
        private EmbeddingProvider $embeddings
    ) {
    }

    public function procesar(int $material, int $profesor): array
    {
        $contenido = $this->repositorio->materialAutorizado($material, $profesor);
        $this->repositorio->reservar($material);
        try {
            $this->repositorio->iniciar($material, $profesor);
            try {
                $pdf = base64_decode($contenido, true);
                if ($pdf === false) {
                    throw new RuntimeException('No se pudo leer el PDF.');
                }
                $fragmentos = FragmentadorTutor::fragmentar($this->extractor->extraer($pdf));
                $posicion = 0;
                foreach (array_chunk($fragmentos, 16) as $lote) {
                    $vectores = $this->embeddings->generar(array_column($lote, 'texto'));
                    if (!array_is_list($vectores) || count($vectores) !== count($lote)) {
                        throw new RuntimeException('Cantidad incorrecta de embeddings.');
                    }
                    foreach ($vectores as $vector) {
                        $fragmentos[$posicion++]['embedding'] = VectorTutor::serializar($vector);
                    }
                }
                $estado = $this->repositorio->finalizar($material, $profesor, $fragmentos);
                return ['estado' => $estado, 'fragmentos' => count($fragmentos)];
            } catch (Throwable $error) {
                $mensaje = $error instanceof RuntimeException && !$error instanceof PDOException
                    ? $error->getMessage() : 'No se pudo completar la ingestión.';
                $this->repositorio->marcarError($material, $mensaje);
                throw new RuntimeException($mensaje);
            }
        } finally {
            $this->repositorio->liberar($material);
        }
    }
}
