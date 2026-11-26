<?php

declare(strict_types=1);

final class OllamaKeepAlive
{
    private const VARIABLE = 'TUTOR_OLLAMA_KEEP_ALIVE_SECONDS';

    public static function parametros(): array
    {
        $valor = getenv(self::VARIABLE);

        if ($valor === false) {
            return [];
        }

        if (
            !preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/', $valor)
            || (int) $valor > 3600
        ) {
            throw new RuntimeException(
                'Configuracion keep_alive de Ollama invalida.'
            );
        }

        return [
            'keep_alive' => (int) $valor,
        ];
    }
}
