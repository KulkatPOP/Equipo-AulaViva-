<?php

declare(strict_types=1);

interface AIProvider
{
    public function modelo(): string;

    public function generate(string $systemPrompt, string $userPrompt, array $context, int $timeoutMs): AIResponse;
}

final class AIResponse
{
    public function __construct(public readonly string $texto, public readonly bool $completa)
    {
    }
}

final class TutorGenerationTimeout extends RuntimeException
{
}

final class OllamaAIProvider implements AIProvider
{
    public function __construct(private string $url, private string $modelo)
    {
        $partes = parse_url($url);
        $hostDockerPermitido = getenv('OLLAMA_ALLOW_DOCKER_HOST') === 'true'
            && ($partes['host'] ?? '') === 'host.docker.internal'
            && ($partes['scheme'] ?? '') === 'http'
            && ($partes['port'] ?? null) === 11434;
        if (!is_array($partes) || !in_array($partes['scheme'] ?? '', ['http', 'https'], true)
            || (!in_array($partes['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
                && !$hostDockerPermitido)
            || array_intersect_key($partes, array_flip(['user', 'pass', 'query', 'fragment']))
            || preg_match('/(?:^|[-:])cloud(?:$|[-:])/i', $modelo)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,80}\z/', $modelo)) {
            throw new RuntimeException('Configuración local del generador inválida.');
        }
    }

    public static function desdeEntorno(): self
    {
        return new self(getenv('OLLAMA_BASE_URL') ?: '', getenv('OLLAMA_CHAT_MODEL') ?: '');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    public function generate(string $systemPrompt, string $userPrompt, array $context, int $timeoutMs): AIResponse
    {
        $fragmentos = [];
        $hechos = [];
        $citas = [];
        foreach ($context as $ficha) {
            $etiqueta = $ficha['label'];
            $cita = "[$etiqueta, p. {$ficha['page']}]";
            $fragmentos[] = "[$etiqueta]\nPágina: {$ficha['page']}\nTexto:\n{$ficha['text']}"
                . "\nHechos obligatorios con cita $cita: "
                . json_encode($ficha['facts'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $citas[] = $cita;
            foreach ($ficha['facts'] as $hecho) {
                $hechos[$hecho] = true;
            }
        }
        $formato = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['claims'],
            'properties' => ['claims' => [
                'type' => 'array', 'minItems' => count($hechos), 'maxItems' => count($hechos),
                'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['text', 'citation'],
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'citation' => ['type' => 'string', 'enum' => $citas],
                    ],
                ],
            ]],
        ];
        $http = curl_init(rtrim($this->url, '/') . '/api/generate');
        if ($http === false) {
            throw new RuntimeException('No se pudo iniciar la conexión con el generador.');
        }
        try {
            curl_setopt_array($http, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMs),
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $this->modelo,
                    'system' => $systemPrompt,
                    'prompt' => "Pregunta:\n" . $userPrompt . "\n\n" . implode("\n\n", $fragmentos),
                    'format' => $formato,
                    'think' => false,
                    'stream' => false,
                    'options' => ['temperature' => 0, 'num_predict' => 180],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $json = curl_exec($http);
            if (curl_errno($http) === CURLE_OPERATION_TIMEDOUT) {
                throw new TutorGenerationTimeout();
            }
            if (!is_string($json) || curl_getinfo($http, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('El generador no está disponible.');
            }
        } finally {
            curl_close($http);
        }

        $datos = json_decode($json, true);
        if (!is_string($datos['response'] ?? null)) {
            throw new RuntimeException('El generador devolvió un formato no disponible.');
        }
        return new AIResponse($datos['response'], ($datos['done'] ?? false) === true && ($datos['done_reason'] ?? '') === 'stop');
    }
}
