<?php

declare(strict_types=1);

interface ExtractorInterface
{
    /** @return array<int, string> Texto por número de página. */
    public function extraer(string $pdf): array;
}

final class PdfTextExtractor implements ExtractorInterface
{
    /** @param list<string> $comando */
    private function __construct(private array $comando)
    {
    }

    public static function desdeEntorno(): self
    {
        $contenedor = getenv('TUTOR_PDFTOTEXT_CONTAINER');
        if ($contenedor !== false && $contenedor !== '') {
            if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $contenedor)) {
                throw new RuntimeException('El nombre del contenedor de pdftotext es inválido.');
            }
            return new self(['docker', 'exec', '-i', $contenedor, 'pdftotext']);
        }
        return new self([getenv('TUTOR_PDFTOTEXT_BIN') ?: 'pdftotext']);
    }

    public function extraer(string $pdf): array
    {
        if (!str_starts_with($pdf, '%PDF-') || !str_ends_with(rtrim($pdf), '%%EOF')) {
            throw new RuntimeException('PDF inválido.');
        }
        $this->ejecutar(['-v']);
        $texto = $this->ejecutar(['-enc', 'UTF-8', '-', '-'], $pdf);
        if (!mb_check_encoding($texto, 'UTF-8') || str_contains($texto, "\0")) {
            throw new RuntimeException('El PDF no produjo texto UTF-8 válido.');
        }
        $paginas = [];
        foreach (explode("\f", $texto) as $indice => $pagina) {
            if (preg_match('/\S/u', $pagina) === 1) {
                $paginas[$indice + 1] = $pagina;
            }
        }
        return $paginas;
    }

    private function ejecutar(array $argumentos, string $entrada = ''): string
    {
        $recursos = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $archivo = tmpfile();
                if ($archivo === false) {
                    throw new RuntimeException('No se pudieron reservar archivos temporales para el PDF.');
                }
                $recursos[] = $archivo;
            }
            if (fwrite($recursos[0], $entrada) !== strlen($entrada) || !rewind($recursos[0])) {
                throw new RuntimeException('No se pudo preparar el PDF para la extracción.');
            }
            $proceso = @proc_open([...$this->comando, ...$argumentos], $recursos, $tuberias);
            if (!is_resource($proceso) || proc_close($proceso) !== 0) {
                throw new RuntimeException($argumentos === ['-v']
                    ? 'pdftotext no está disponible. Revisa TUTOR_PDFTOTEXT_CONTAINER o TUTOR_PDFTOTEXT_BIN.'
                    : 'pdftotext no pudo extraer este PDF.');
            }
            rewind($recursos[1]);
            $texto = stream_get_contents($recursos[1]);
            if ($texto === false) {
                throw new RuntimeException('No se pudo leer la salida de pdftotext.');
            }
            return $texto;
        } finally {
            foreach ($recursos as $archivo) {
                fclose($archivo);
            }
        }
    }
}

final class FragmentadorTutor
{
    /** @return list<array{orden: int, pagina: int, texto: string}> */
    public static function fragmentar(array $paginas): array
    {
        $fragmentos = [];
        foreach ($paginas as $numero => $pagina) {
            while ($pagina !== '') {
                $trozo = mb_substr($pagina, 0, 1200, 'UTF-8');
                $corte = mb_strlen($trozo, 'UTF-8');
                if ($trozo !== $pagina && preg_match('/\s+\S*$/u', $trozo, $coincidencia, PREG_OFFSET_CAPTURE)) {
                    $limite = mb_strlen(substr($trozo, 0, $coincidencia[0][1]), 'UTF-8');
                    if ($limite >= 600) {
                        $corte = $limite;
                        $trozo = mb_substr($trozo, 0, $corte, 'UTF-8');
                    }
                }
                if (preg_match('/\S/u', $trozo) === 1) {
                    $fragmentos[] = ['orden' => count($fragmentos) + 1, 'pagina' => $numero, 'texto' => $trozo];
                }
                $pagina = mb_substr($pagina, $corte, null, 'UTF-8');
            }
        }
        return $fragmentos;
    }
}
