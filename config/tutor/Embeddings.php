<?php

declare(strict_types=1);

interface EmbeddingProvider
{
    /** @param list<string> $textos @return list<list<float>> */
    public function generar(array $textos): array;
}

final class VectorTutor
{
    public const DIMENSION = 768;

    public static function serializar(mixed $vector): string
    {
        if (!is_array($vector) || !array_is_list($vector) || count($vector) !== self::DIMENSION) {
            throw new RuntimeException('El embedding debe tener exactamente 768 componentes.');
        }
        $noNulo = false;
        foreach ($vector as $valor) {
            if ((!is_int($valor) && !is_float($valor)) || !is_finite($valor) || abs($valor) > 3.4028234e38) {
                throw new RuntimeException('El embedding contiene componentes inválidos.');
            }
            $noNulo = $noNulo || abs($valor) >= 1.4012985e-45;
        }
        if (!$noNulo) {
            throw new RuntimeException('El embedding nulo no admite distancia coseno.');
        }
        return json_encode($vector, JSON_THROW_ON_ERROR);
    }
}

final class TutorEmbeddingTimeout extends RuntimeException
{
}

final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private string $url, private string $modelo, private int $timeoutMs = 30000)
    {
        $partes = parse_url($url);
        if ($timeoutMs < 1 || !is_array($partes) || !in_array($partes['scheme'] ?? '', ['http', 'https'], true)
            || empty($partes['host']) || array_intersect_key($partes, array_flip(['user', 'pass', 'query', 'fragment']))
            || !in_array($modelo, ['nomic-embed-text', 'nomic-embed-text:latest'], true)) {
            throw new RuntimeException('Configura OLLAMA_BASE_URL y OLLAMA_EMBEDDING_MODEL=nomic-embed-text.');
        }
    }

    public static function desdeEntorno(int $timeoutMs = 30000): self
    {
        return new self(getenv('OLLAMA_BASE_URL') ?: '', getenv('OLLAMA_EMBEDDING_MODEL') ?: '', $timeoutMs);
    }

    public function generar(array $textos): array
    {
        if ($textos === []) {
            return [];
        }
        $http = curl_init(rtrim($this->url, '/') . '/api/embed');
        if ($http === false) {
            throw new RuntimeException('No se pudo preparar la conexión con Ollama.');
        }
        try {
            curl_setopt_array($http, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $this->timeoutMs),
                CURLOPT_TIMEOUT_MS => $this->timeoutMs,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $this->modelo, 'input' => $textos, 'truncate' => false,
                ], JSON_THROW_ON_ERROR),
            ]);
            $json = curl_exec($http);
            if (curl_errno($http) === CURLE_OPERATION_TIMEDOUT) {
                throw new TutorEmbeddingTimeout('El proveedor de embeddings agotó el tiempo de espera.');
            }
            if (!is_string($json) || curl_getinfo($http, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('Ollama no pudo generar los embeddings.');
            }
        } finally {
            curl_close($http);
        }

        $datos = json_decode($json, true);
        $vectores = $datos['embeddings'] ?? null;
        if (!is_array($vectores) || !array_is_list($vectores) || count($vectores) !== count($textos)) {
            throw new RuntimeException('Ollama devolvió una cantidad incorrecta de embeddings.');
        }
        foreach ($vectores as $vector) {
            VectorTutor::serializar($vector);
        }
        return $vectores;
    }
}
