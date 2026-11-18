<?php

declare(strict_types=1);

require_once __DIR__ . '/Embeddings.php';
require_once __DIR__ . '/TutorLexico.php';

final class TutorRetriever
{
    public function __construct(
        private PDO $pdo,
        private EmbeddingProvider $embeddings,
        private string $configTexto = 'simple'
    ) {
        if (!in_array($configTexto, ['simple', 'english', 'spanish'], true)) {
            throw new InvalidArgumentException('Configuración de búsqueda textual no admitida.');
        }
    }

    /**
     * El consumidor debe iniciar la sesión existente. Nunca se reciben alumno ni colegio del cliente.
     * Una lista vacía indica ausencia de candidatos autorizados.
     */
    public function buscar(int $aula, string $pregunta, ?int $material = null): array
    {
        $pregunta = trim($pregunta);
        $respuesta = ['query' => $pregunta, 'results' => []];
        $usuario = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        $colegio = filter_var($_SESSION['colegio_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if (session_status() !== PHP_SESSION_ACTIVE || ($_SESSION['rol'] ?? null) !== 'alumno'
            || !$usuario || !$colegio || $aula < 1 || $aula > 2147483647 || ($material !== null && $material < 1)) {
            return $respuesta;
        }
        if (preg_match('/\S/u', $pregunta) !== 1 || str_contains($pregunta, "\0") || mb_strlen($pregunta, 'UTF-8') > 1500) {
            throw new InvalidArgumentException('Ingresa una pregunta de 1 a 1500 caracteres.');
        }

        $alcance = "
            FROM usuarios u
            JOIN aula_alumnos aa ON aa.alumno_id = u.id
            JOIN aulas a ON a.id = aa.aula_id AND a.colegio_id = u.colegio_id
            JOIN materiales m ON m.aula_id = a.id
            JOIN material_ingestas i ON i.material_id = m.id AND i.estado = 'procesado'
            JOIN material_fragmentos f ON f.material_id = m.id AND f.estado = 'procesado'
            WHERE u.id = :usuario AND u.rol = 'alumno' AND u.activo
                AND u.colegio_id = :colegio AND a.id = :aula AND a.activo";
        $params = ['usuario' => $usuario, 'colegio' => $colegio, 'aula' => $aula];
        if ($material !== null) {
            $alcance .= ' AND m.id = :material';
            $params['material'] = $material;
        }
        $tieneEvidencia = $this->pdo->prepare("SELECT 1 $alcance LIMIT 1");
        $tieneEvidencia->execute($params);
        if (!$tieneEvidencia->fetchColumn()) {
            return $respuesta;
        }

        $vectores = $this->embeddings->generar([$pregunta]);
        if (!array_is_list($vectores) || count($vectores) !== 1) {
            throw new RuntimeException('El proveedor debe devolver un embedding para la pregunta.');
        }
        // La misma autorización se aplica a los candidatos después de consultar al proveedor.
        $stmt = $this->pdo->prepare("
            WITH permitidos AS MATERIALIZED (
                SELECT f.id, f.material_id, f.pagina, f.texto, f.embedding $alcance
            ), entrada AS (
                SELECT CAST(:vector AS vector(768)) AS vector,
                       CAST(:config AS regconfig) AS idioma, CAST(:pregunta AS text) AS pregunta
            ), consulta AS (
                SELECT e.*, (
                    SELECT CAST(string_agg(quote_literal(lexema), ' | ') AS tsquery)
                    FROM unnest(tsvector_to_array(to_tsvector(e.idioma, e.pregunta))) AS palabras(lexema)
                ) AS terminos
                FROM entrada e
            ), top_sem AS (
                SELECT p.id, p.embedding <=> c.vector AS distancia
                FROM permitidos p CROSS JOIN consulta c ORDER BY distancia, p.id LIMIT 5
            ), top_txt AS (
                SELECT p.id, ts_rank_cd(to_tsvector(c.idioma, p.texto), c.terminos) AS puntuacion
                FROM permitidos p CROSS JOIN consulta c
                WHERE to_tsvector(c.idioma, p.texto) @@ c.terminos
                ORDER BY puntuacion DESC, p.id LIMIT 5
            ), sem AS (
                SELECT *, row_number() OVER (ORDER BY distancia, id) AS posicion FROM top_sem
            ), txt AS (
                SELECT *, row_number() OVER (ORDER BY puntuacion DESC, id) AS posicion FROM top_txt
            )
            SELECT p.id AS fragment_id, p.material_id, p.pagina AS page, p.texto AS text,
                   s.distancia AS semantic_distance, t.puntuacion AS text_score,
                   s.posicion AS semantic_rank, t.posicion AS text_rank,
                   COALESCE(1.0 / (60 + s.posicion), 0) + COALESCE(1.0 / (60 + t.posicion), 0) AS rrf_score
            FROM sem s FULL JOIN txt t ON t.id = s.id
            JOIN permitidos p ON p.id = COALESCE(s.id, t.id)
            ORDER BY rrf_score DESC, p.id
        ");
        $stmt->execute($params + [
            'vector' => VectorTutor::serializar($vectores[0]), 'config' => $this->configTexto, 'pregunta' => TutorLexico::ampliar($pregunta),
        ]);
        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidatos as &$candidato) {
            $candidato['lexical_coverage'] = TutorLexico::cobertura($pregunta, $candidato['text']);
            $candidato['combined_score'] = (float) $candidato['rrf_score'] + 0.014 * $candidato['lexical_coverage'];
        }
        unset($candidato);
        usort($candidatos, static fn ($a, $b) =>
            ($b['combined_score'] <=> $a['combined_score']) ?: ($a['fragment_id'] <=> $b['fragment_id']));
        $respuesta['results'] = array_slice($candidatos, 0, 3);
        return $respuesta;
    }
}
