<?php

declare(strict_types=1);

/** Equivalencias generales de medición e instrucciones escolares. */
final class TutorLexico
{
    private const GRUPOS = [
        'profundidad deep depth', 'bandeja pan pans tray recipiente container', 'minimo minima minimum least', 'maximo maxima maximum most',
        'altura height altitude', 'primero primera first initial', 'espesor thickness thick',
        'anchura ancho width wide', 'longitud length long', 'diametro diameter', 'masa mass', 'peso weight',
        'cantidad count number numero', 'ensayo ensayos trial trials prueba pruebas', 'promedio average mean',
        'tiempo time duration duracion dura duro', 'dia dias day days', 'cada daily every diariamente diario',
        'segundo segundos second seconds', 'minuto minutos minute minutes', 'hora horas hour hours',
        'centimetro centimetros centimeter centimeters cm', 'metro metros meter meters metre metres m',
        'tamaño tamano size', 'velocidad speed velocity', 'superficie surface', 'geologia geology',
        'factor factores factors', 'apariencia aspecto appearance', 'metodo metodos method methods mecanismo mecanismos',
        'naturaleza nature natural', 'formar form formed formation forming', 'colapso collapse',
        'medir mide mida miden measure measures measuring', 'resultado resultados result results outcome',
        'caer caida drop dropped', 'paso step steps', 'registrar registra record write anotar',
        'dibujar dibujos ilustrar illustrative illustrate draw', 'cambio cambios change changes',
        'observar observan observacion observaciones observe observation observations check revisar',
        'ambas ambos both', 'planta plantas plant plants', 'hojas leaves', 'cubiertas cubiertos covered tapadas',
        'expuestas expuestos exposed descubiertas', 'control', 'experimental experiment experimento',
        'ecuacion equation', 'luz sunlight', 'agua water', 'oxigeno oxygen', 'carbono carbon', 'glucosa glucose',
        'volcan volcano volcanico volcanic', 'impacto impact', 'proyectil projectile',
        'objeto object', 'material substance', 'estudiante estudiantes student students', 'importante importantes important',
    ];

    private const VACIAS = [
        'a', 'al', 'ante', 'como', 'con', 'cual', 'cuales', 'cuando', 'cuanto', 'cuantos', 'cuanta', 'de',
        'del', 'desde', 'donde', 'el', 'ella', 'en', 'es', 'esta', 'este', 'ha', 'hay', 'la', 'las', 'le', 'lo',
        'los', 'o', 'para', 'por', 'que', 'se', 'si', 'su', 'sus', 'un', 'una', 'y', 'debe', 'deben',
        'must', 'the', 'of', 'to', 'for', 'a', 'an', 'at', 'and', 'or', 'on', 'in', 'is', 'was', 'how',
        'from', 'with', 'what', 'when', 'which', 'does', 'did', 'each', 'be', 'as', 'has', 'have',
    ];

    public static function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', strtr(mb_strtolower($texto, 'UTF-8'), [
            'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u', 'ü'=>'u',
            '→'=>'->', '–'=>'-', '—'=>'-', "\u{00a0}"=>' ',
        ])) ?? '');
    }

    public static function terminos(string $texto): array
    {
        preg_match_all('/[\p{L}]+|\d+(?:[.,]\d+)?/u', self::normalizar($texto), $m);
        return array_values(array_unique(array_diff(array_map(
            static fn (string $p): string => str_replace(',', '.', $p), $m[0]
        ), self::VACIAS)));
    }

    private static function equivalencias(): array
    {
        static $mapa;
        if ($mapa === null) {
            foreach (self::GRUPOS as $grupo) {
                $formas = explode(' ', $grupo);
                foreach ($formas as $forma) {
                    $mapa[$forma] = $formas;
                }
            }
        }
        return $mapa;
    }

    public static function ampliar(string $consulta): string
    {
        $mapa = self::equivalencias();
        $terminos = [];
        foreach (self::terminos($consulta) as $token) {
            foreach ($mapa[$token] ?? [$token] as $forma) {
                $terminos[$forma] = true;
            }
        }
        foreach (array_keys($terminos) as $forma) {
            foreach (self::variantes($forma) as $variante) {
                $terminos[$variante] = true;
                $traducciones = self::diccionario()['es'][$variante] ?? [];
                // Evitar añadir todas las acepciones de términos polisémicos al OR textual.
                if (count($traducciones) === 1) {
                    $terminos[array_key_first($traducciones)] = true;
                }
            }
        }
        preg_match_all('/\p{L}+/u', self::normalizar($consulta), $palabras);
        $lexico = self::diccionario()['es'];
        for ($i = 0, $n = count($palabras[0]); $i < $n; $i++) {
            for ($largo = 2; $largo <= 4 && $i + $largo <= $n; $largo++) {
                $frase = implode(' ', array_slice($palabras[0], $i, $largo));
                $traducciones = $lexico[self::singularDeFrase($frase)] ?? [];
                if (count($traducciones) === 1) {
                    $terminos[array_key_first($traducciones)] = true;
                }
            }
        }
        return implode(' ', array_keys($terminos));
    }

    private static function variantes(string $termino): array
    {
        if (strlen($termino) < 5 || !preg_match('/^\p{L}+$/u', $termino)) {
            return [$termino];
        }
        $singular = preg_replace('/(?<=[aeiou])s$/u', '', $termino);
        $alternativa = preg_replace(
            ['/(\p{L})metro$/u', '/(\p{L})cion$/u', '/(\p{L})dad$/u', '/(\p{L})ico$/u'],
            ['$1meter', '$1tion', '$1ty', '$1ic'], $singular
        );
        return array_unique([$termino, $singular, $alternativa]);
    }

    private static function diccionario(): array
    {
        static $diccionario;
        if ($diccionario !== null) {
            return $diccionario;
        }
        $diccionario = ['es' => [], 'en' => []];
        $archivo = gzopen(__DIR__ . '/lexico-en-es.tsv.gz', 'rb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo cargar el léxico bilingüe del Tutor.');
        }
        try {
            while (($linea = gzgets($archivo)) !== false) {
                [$es, $en] = explode("\t", trim($linea), 2);
                $diccionario['es'][self::normalizar($es)][$en] = true;
                $diccionario['en'][$en][$es] = true;
            }
        } finally {
            gzclose($archivo);
        }
        return $diccionario;
    }

    public static function coincideEnConsulta(string $argumento, string $pregunta): bool
    {
        $terminos = self::terminos($argumento);
        if (!$terminos) {
            return false;
        }
        $diccionario = self::diccionario();
        $consulta = self::terminos($pregunta);
        foreach ($terminos as $origen) {
            $en = self::formasIngles($origen);
            $alternativas = array_fill_keys([$origen, ...$en], true);
            foreach ($en as $forma) {
                foreach ($diccionario['en'][$forma] ?? [] as $es => $_) {
                    $alternativas[self::normalizar($es)] = true;
                }
            }
            if (!array_filter($consulta, static fn (string $t): bool =>
                isset($alternativas[$t]) || (str_ends_with($t, 's') && isset($alternativas[substr($t, 0, -1)])))) {
                return false;
            }
        }
        return true;
    }

    private static function formasIngles(string $token): array
    {
        return str_ends_with($token, 's') && strlen($token) > 4 ? [$token, substr($token, 0, -1)] : [$token];
    }

    public static function fraseCorresponde(string $ingles, string $espanol): bool
    {
        // La flexión se usa para buscar una entrada multitémino, que debe
        // tener una traducción registrada. No compara bolsas de palabras.
        $esp = self::singularDeFrase(self::normalizar($espanol));
        foreach (self::formasIngles(self::normalizar($ingles)) as $forma) {
            foreach (self::diccionario()['en'][$forma] ?? [] as $traduccion => $_) {
                if (self::normalizar($traduccion) === $esp) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function singularDeFrase(string $frase): string
    {
        return preg_replace('/^([a-zñ]{3,}[aeiou])s(?= |$)/u', '$1', $frase);
    }

    public static function nombreEnConsulta(string $nombre, string $pregunta): ?string
    {
        $permitidos = [];
        foreach (self::formasIngles($nombre) as $forma) {
            foreach (self::diccionario()['en'][$forma] ?? [] as $es => $_) {
                $permitidos[self::normalizar($es)] = true;
            }
        }
        $coincidencias = [];
        foreach (self::terminos($pregunta) as $termino) {
            if (isset($permitidos[$termino]) || (str_ends_with($termino, 's') && isset($permitidos[substr($termino, 0, -1)]))) {
                $coincidencias[] = $termino;
            }
        }
        return count($coincidencias) === 1 ? $coincidencias[0] : null;
    }

    private static function raizNominal(string $forma): string
    {
        if (strlen($forma) < 5 || !preg_match('/^\p{L}+$/u', $forma)) {
            return $forma;
        }
        $forma = preg_replace('/(?:s|e|(?<=[aeiou])r)$/u', '', $forma);
        if (strlen($forma) >= 4) {
            $forma = preg_replace('/[ao]$/u', '', $forma);
        }
        return strtr(self::formaAfin($forma), ['k'=>'c']);
    }

    public static function traduccionNominal(string $forma): ?string
    {
        // Equivalencias de vocabulario común. No se usan aproximaciones
        // ortográficas para traducir entidades ausentes de la consulta.
        $traducciones = self::diccionario()['en'][$forma] ?? [];
        return count($traducciones) === 1 ? array_key_first($traducciones) : null;
    }

    private static function formaAfin(string $token): string
    {
        // Correspondencias ortográficas habituales en tecnicismos de raíz griega.
        return strtr($token, ['ph'=>'f', 'th'=>'t', 'y'=>'i']);
    }

    public static function cobertura(string $pregunta, string $texto): float
    {
        $consulta = self::terminos($pregunta);
        if (!$consulta) {
            return 0;
        }
        $disponibles = array_fill_keys(array_map(self::formaAfin(...), self::terminos($texto)), true);
        $raices = array_fill_keys(array_map(self::raizNominal(...), self::terminos($texto)), true);
        $mapa = self::equivalencias();
        $peso = $coincide = 0;
        foreach ($consulta as $termino) {
            $w = is_numeric($termino) || in_array($termino, ['cm', 'kg', 'km', 'mm', 'm', 'ml'], true)
                || in_array($termino, ['minimo','minima','maximo','maxima','minimum','maximum'], true) ? 2 : 1;
            $peso += $w;
            foreach (self::variantes($termino) as $variante) {
                if (isset($raices[self::raizNominal($variante)])) {
                    $coincide += $w;
                    continue 2;
                }
            }
            foreach ($mapa[$termino] ?? [$termino] as $forma) {
                if (isset($disponibles[self::formaAfin($forma)])) {
                    $coincide += $w;
                    break;
                }
            }
        }
        return $coincide / $peso;
    }
}
