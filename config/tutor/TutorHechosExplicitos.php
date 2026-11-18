<?php

declare(strict_types=1);

require_once __DIR__ . '/TutorLexico.php';

/**
 * Extrae relaciones explícitas de una oración. La pregunta sirve de ancla
 * para seleccionar la relación y sus argumentos, nunca de fuente del dato.
 */
final class TutorHechosExplicitos
{
    private const NOMBRE = '[\p{L}][\p{L}\p{M} -]{1,70}?';
    private const VALOR = '[^.!?]{2,200}';

    public static function extraer(string $pregunta, string $pasaje, string $fragmento): ?string
    {
        $q = TutorLexico::normalizar($pregunta);
        $t = mb_strtolower(trim($pasaje, " \t\r\n.!"), 'UTF-8');
        if (str_contains($t, '?') || preg_match('/\b(?:not|never|nunca|no|supongamos|hypothesis|hipotesis|quizas|unless|except)\b/u', TutorLexico::normalizar($t))) {
            return null;
        }

        $gramaticas = [
            'instrumento' => ['\binstrumento\b', '(?:el |la )?(' . self::NOMBRE . ') (?:mide|sirve para medir) (' . self::VALOR . ')'],
            'lista' => ['\b(?:elementos|componentes|partes)\b', '(' . self::NOMBRE . ') (?:incluye|contiene|consta de) (' . self::VALOR . ')'],
            'material' => ['\bmaterial(?:es)?\b', '(' . self::NOMBRE . ') (?:puede ser de|se fabrica con|se hace con|esta hecho de|está hecho de) (' . self::VALOR . ')'],
            'direccion' => ['\b(?:donde|direccion)\b', '(' . self::NOMBRE . ') (?:apunta|se orienta|se dirige) (?:hacia|al) (' . self::VALOR . ')'],
            'cantidad' => ['\b(?:cuantos?|cuantas?|minim[oa])\b', '(' . self::NOMBRE . ') (?:requiere|necesita) (?:al menos |como mínimo |como minimo )?([0-9]+(?:[.,][0-9]+)? ' . self::NOMBRE . ')'],
            'formula' => ['\b(?:calcula|calcular|formula)\b', '(' . self::NOMBRE . ') se (?:calcula|obtiene) (?:multiplicando|dividiendo|sumando|restando) (' . self::VALOR . ')'],
            'pasos' => ['\bcomo\b', 'para ([^,]{4,80}), (primero ' . self::VALOR . ')'],
            'causa' => ['\b(?:por que|cual es la causa)\b', '(' . self::NOMBRE . ' (?:se )?' . self::VALOR . ') (?:porque|debido a que|ya que) (' . self::VALOR . ')'],
        ];

        foreach ($gramaticas as $relacion => [$intencion, $patron]) {
            if (!preg_match('/' . $intencion . '/u', $q) || !preg_match('/^' . $patron . '$/u', $t, $partes)) {
                continue;
            }
            $buscado = match ($relacion) {
                'instrumento' => $partes[2],
                'cantidad' => substr($partes[2], strpos($partes[2], ' ') + 1),
                default => $partes[1],
            };
            if (!self::ancla($buscado, $q) || ($relacion === 'cantidad' && !self::ancla($partes[1], $q))) {
                continue;
            }
            if ($relacion === 'cantidad'
                && (!preg_match('/\b(?:requiere|necesita|requieren|necesitan)\b/', $q)
                    || (bool) preg_match('/\bminim[oa]\b/', $q) !== (bool) preg_match('/\b(?:al menos|como minimo)\b/', TutorLexico::normalizar($t)))) {
                continue;
            }
            if ($relacion === 'pasos' && !preg_match('/\b(?:luego|despues|por ultimo)\b/', TutorLexico::normalizar($partes[2]))) {
                continue;
            }
            return self::oracion($t);
        }

        // Nombres técnicos de instrumentos con una contraparte morfológica
        // regular. El propósito debe aparecer en el mismo enunciado.
        if (preg_match('/\binstrumento\b/', $q)
            && preg_match('/^(?:build\s+)?(?:a|an|the) (\p{L}+) (?:to measure|measures) (' . self::VALOR . ')$/u', $t, $m)
            && TutorLexico::coincideEnConsulta($m[2], $q)) {
            $instrumento = TutorLexico::traduccionNominal($m[1]);
            return $instrumento === null ? null : self::oracion($instrumento);
        }

        // Ante un límite inferior, conservar el operador y el sustantivo.
        // La coincidencia de la entidad se vuelve a exigir aunque el vector sea cercano.
        if (preg_match('/\bminim[oa]\b/', $q)
            && preg_match('/\b(at least|al menos|como minimo) (\d+|one|two|three|four|five|six|seven|eight|nine|ten) (\p{L}+)\b/u', TutorLexico::normalizar($t), $m)
            && ($nombre = TutorLexico::nombreEnConsulta($m[3], $q)) !== null
            && TutorLexico::cobertura($q, $fragmento) >= .25) {
            return self::oracion('al menos ' . EvidenceGate::numero($m[2]) . ' ' . $nombre);
        }

        $cantidadInventario = self::cantidadInventario($q, $t, $fragmento);
        if ($cantidadInventario !== null) {
            return $cantidadInventario;
        }

        // Traducción cerrada de una relación simple. Si alguno de sus
        // argumentos queda sin traducir con certeza, se abstiene.
        if (preg_match('/\b(?:necesita|requiere)\b/', $q)
            && preg_match('/^(?:the|a) (' . self::NOMBRE . ') requires (' . self::NOMBRE . ')$/u', $t, $m)) {
            $sujeto = TutorLexico::traduccionNominal($m[1]);
            $objeto = TutorLexico::traduccionNominal($m[2]);
            if ($sujeto !== null && $objeto !== null && self::ancla($sujeto, $q)) {
                return self::oracion($sujeto . ' requiere ' . $objeto);
            }
        }
        return null;
    }

    private static function cantidadInventario(string $q, string $t, string $fragmento): ?string
    {
        if (!preg_match('/^¿?cuant[oa]s ([^?]{3,80}) se (?:necesitan|requieren) para (?:crear|construir|hacer|preparar) (?:el|la|los|las) ([^?]{3,80})\??$/u', $q, $consulta)
            || !preg_match('/^(?:[•*r-] )?(' . EvidenceGate::CANTIDAD . ') (\p{L}+(?: \p{L}+){0,4})(?: [–—-] .+)?$/u', $t, $dato)
            || !TutorLexico::fraseCorresponde($dato[2], $consulta[1])) {
            return null;
        }
        // Un ítem aislado no prueba a qué actividad pertenece.
        $inicio = mb_strpos(mb_strtolower($fragmento, 'UTF-8'), $t, 0, 'UTF-8');
        if ($inicio === false) {
            return null;
        }
        $previo = mb_substr($fragmento, 0, $inicio, 'UTF-8');
        if (!preg_match('/^(?:materials(?: needed)?|equipment|what you will need|supplies|required items)[: ]*\R((?:[^\r\n]*\R)*)\z/imu', $previo, $inventario)
            || preg_match('/^(?:how to|procedure|instructions|\d+[.)])/imu', $inventario[1])
            || preg_match('/\b(?:optional|if|for example|instead)\b/u', $t)) {
            return null;
        }
        $titulos = preg_grep('/^(?:build|construct|make|prepare) /iu', preg_split('/\R/u', $previo));
        $titulo = $titulos ? end($titulos) : '';
        if (!preg_match('/^(?:build|construct|make|prepare) (?:a|an|the) (.+?)(?: (?:to|for) .+)?$/iu', trim($titulo), $actividad)
            || !TutorLexico::coincideEnConsulta($actividad[1], $consulta[2])) {
            return null;
        }
        return self::oracion(EvidenceGate::numero($dato[1]) . ' ' . $consulta[1]);
    }

    public static function firmaLista(string $texto): ?string
    {
        $normal = TutorLexico::normalizar(trim($texto, " ."));
        if (!preg_match('/^(.+?) (incluye|contiene|consta de) ([^.!?:;]+)$/u', $normal, $m)) {
            return null;
        }
        // Solo listas explícitas y planas. Conservamos las alternativas,
        // los calificadores y el sujeto. No reordenamos acciones o causas.
        if (preg_match('/\b(?:primero|luego|despues|orden|antes|finalmente)\b/u', $m[3])
            || (preg_match('/\b(?:y|e)\b/u', $m[3]) && preg_match('/\b(?:o|u)\b/u', $m[3]))) {
            return null;
        }
        $union = preg_match('/\b(?:o|u)\b/u', $m[3]) ? 'o' : 'y';
        $elementos = preg_split('/,\s*|\s+(?:y|e|o|u)\s+/u', $m[3], -1, PREG_SPLIT_NO_EMPTY);
        if (count($elementos) < 2) {
            return null;
        }
        $elementos = array_map(static fn ($item) => trim(preg_replace('/^(?:el|la|los|las) /u', '', trim($item))), $elementos);
        sort($elementos, SORT_STRING);
        return 'lista:' . $m[1] . ':' . ($m[2] === 'consta de' ? 'exhaustiva:' : '') . $union . ':' . implode('|', $elementos);
    }

    private static function ancla(string $t, string $q): bool
    {
        return TutorLexico::cobertura($t, $q) >= .66;
    }

    private static function oracion(string $frase): string
    {
        return mb_strtoupper(mb_substr($frase, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($frase, 1, null, 'UTF-8') . '.';
    }
}
