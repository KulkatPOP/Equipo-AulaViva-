<?php

declare(strict_types=1);

require_once __DIR__ . '/TutorLexico.php';
require_once __DIR__ . '/TutorHechosExplicitos.php';
require_once __DIR__ . '/TutorEvidencia.php';

final class EvidenceGate
{
    public const CANTIDAD = '\d+(?:[.,]\d+)?|one|two|three|four|five|six|seven|eight|nine|ten|un[oa]?|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez';
    private const UNIDAD = 'cm|mm|m|km|g|kg|ml|l|centimeters?|centimetros?|meters?|metres?|metros?|seconds?|segundos?|minutes?|minutos?|hours?|horas?|days?|dias?';

    /**
     * La forma de la consulta selecciona una relación comprobable, no un dato.
     * El checklist sale exclusivamente de los pasajes recuperados.
     */
    public function preparar(string $pregunta, array $resultados, int $maxFragmentos): array
    {
        $pregunta = TutorLexico::normalizar($pregunta);
        $tipo = $this->relacion($pregunta);

        $opciones = [];
        $estructuradas = [];
        foreach (array_slice($resultados, 0, $maxFragmentos) as $indice => $resultado) {
            $id = filter_var($resultado['fragment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $material = filter_var($resultado['material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $pagina = filter_var($resultado['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $texto = $resultado['text'] ?? null;
            if (!$id || !$material || !$pagina || !is_string($texto) || preg_match('/\S/u', $texto) !== 1) {
                continue;
            }
            $hechoEstructurado = TutorEvidencia::extraer($pregunta, $texto);
            if ($hechoEstructurado !== null) {
                $estructuradas[$hechoEstructurado->firma()] = [
                    'label' => 'F' . ($indice + 1), 'fragment_id' => $id, 'page' => $pagina,
                    'text' => $hechoEstructurado->pasaje, 'facts' => [$hechoEstructurado->texto],
                    'estructura' => $hechoEstructurado,
                ];
            }

            // Reconocer encabezados independientes del material educativo.
            // Reconocer encabezados educativos al inicio del fragmento,
            // sin separar condiciones o hipótesis del contenido posterior.
            $textoSegmentado = $texto;

            $lineasIniciales = preg_split('/\R/u', $texto);

            if (is_array($lineasIniciales)) {
                foreach ($lineasIniciales as $indiceLinea => $linea) {
                    $encabezado = trim($linea);

                    if ($encabezado === '') {
                        continue;
                    }

                    $normalizado = TutorLexico::normalizar($encabezado);

                    $esEncabezado = preg_match(
                        '/^(?:ciencias naturales|ciencias sociales|matematicas|lenguaje|historia|geografia|biologia|quimica|fisica|tecnologia|actividad experimental|materiales necesarios|materiales|unidad\s+\d+\s*:\s*materiales)$/u',
                        $normalizado
                    ) === 1;

                    if (!$esEncabezado) {
                        break;
                    }

                    $lineasIniciales[$indiceLinea] = $encabezado . '.';
                }

                $textoSegmentado = implode("\n", $lineasIniciales);
            }

            $frases = preg_split(
                '/(?<=[.!?])\s+(?=[\p{Lu}\d])|\n\s*\n/u',
                $textoSegmentado,
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if (!$frases) {
                continue;
            }
            $lineas = preg_grep('/^(?:(?:build|construct|make) |(?:[•*r-] )?(?:' . self::CANTIDAD . ') )[^!?]{3,120}$/iu',
                preg_split('/\R/u', $texto));
            foreach ([...$frases, ...$lineas] as $i => $frase) {
                $ventana = $frase . (isset($frases[$i + 1]) ? ' ' . $frases[$i + 1] : '');
                // Dejar que el dato y su antecedente aparezcan en oraciones consecutivas.
                foreach ([$frase, $ventana] as $pasaje) {
                    if ($tipo === 'ecuacion' && preg_match('/^.*(?:--?>|→).*$/mu', $pasaje, $linea)) {
                        $pasaje = trim($linea[0]);
                    }
                    $pasaje = trim(preg_replace('/\s+/u', ' ', $pasaje) ?? '');
                    if (mb_strlen($pasaje, 'UTF-8') > 850) {
                        continue;
                    }
                    $hecho = $tipo === null ? null
                        : $this->extraer($tipo, $pregunta, TutorLexico::normalizar($pasaje), TutorLexico::normalizar($texto));
                    $hecho ??= TutorHechosExplicitos::extraer($pregunta, $pasaje, $texto);
                    if ($hecho === null) {
                        continue;
                    }
                    $clave = self::firma($hecho);
                    $puntuacion = TutorLexico::cobertura($pregunta, $pasaje);
                    if (!isset($opciones[$clave]) || $puntuacion > $opciones[$clave]['coverage']) {
                        $opciones[$clave] = [
                            'label' => 'F' . ($indice + 1), 'fragment_id' => $id, 'page' => $pagina,
                            'text' => $pasaje, 'fact' => $hecho, 'coverage' => $puntuacion,
                        ];
                    }
                    break;
                }
            }
        }

        if ($estructuradas) {
            if (count($estructuradas) !== 1) {
                return [];
            }
            $ficha = reset($estructuradas);
            $etiqueta = $ficha['label'];
            unset($ficha['label']);
            return [$etiqueta => $ficha];
        }
        // Una pregunta con dos valores incompatibles exige más contexto, no una elección arbitraria.
        if (count($opciones) !== 1) {
            return [];
        }
        $evidencia = reset($opciones);
        return [$evidencia['label'] => [
            'fragment_id' => $evidencia['fragment_id'], 'page' => $evidencia['page'],
            'text' => $evidencia['text'], 'facts' => [$evidencia['fact']],
        ]];
    }

    private function relacion(string $q): ?string
    {
        return match (true) {
            preg_match('/\b(?:se midio|se obtuvo|se observo|ocurrio|what happened|was measured|was observed)\b/', $q) === 1 => null,
            preg_match('/\b(?:ecuacion|equation)\b/', $q) === 1 => 'ecuacion',
            preg_match('/\bcontrol\b/', $q) && preg_match('/\bexperimental\b/', $q) => 'contraste',
            preg_match('/\b(?:dur[ao]|duracion|how long)\b/', $q) === 1 => 'duracion',
            preg_match('/\b(?:factores?|factors?)\b/', $q) === 1 => 'factores',
            preg_match('/\b(?:minim[oa]|minimum|at least)\b/', $q) === 1 => 'minimo',
            preg_match('/\b(?:espesor|thickness)\b/', $q) === 1 => 'espesor',
            preg_match('/\b(?:altura|height)\b/', $q) && preg_match('/\b(?:primer[oa]|first|initial)\b/', $q) => 'inicio',
            preg_match('/\b(?:cuantos?|how many)\b.*\b(?:ensayos?|trials?)\b/', $q) === 1 => 'ensayos',
            preg_match('/\b(?:observar|observarse|observe|record|registrar)\b/', $q) === 1 => 'observacion',
            preg_match('/\b(?:medir|miden|measure)\b/', $q) === 1 => 'medicion',
            default => null,
        };
    }

    private function extraer(string $tipo, string $q, string $t, string $fragmento): ?string
    {
        $numero = '(' . self::CANTIDAD . ')';
        $unidad = '(' . self::UNIDAD . ')';
        if (preg_match('/\b(?:not|never|nunca|no se debe)\s+(?:measure|cover|drop|medir|cubrir|dejar caer)\b/', $t)) {
            return null;
        }

        switch ($tipo) {
            case 'ecuacion':
                if (preg_match('/\b(?:ecuacion (?:de(?: la|l)?|para)|equation (?:of|for))\s+([^?]+)/', $q, $tema)
                    && TutorLexico::cobertura($tema[1], $fragmento) < .66) {
                    break;
                }
                if (preg_match('/((?:[a-z0-9 ]+\s*\+\s*)+[a-z0-9 ]+)\s*--?>\s*([a-z0-9 ()]+(?:\s*\+\s*[a-z0-9 ()]+)+)\s*\.?$/', $t, $m)) {
                    $lado = static function (string $texto): ?string {
                        $operandos = array_map(static fn ($x) => self::traducir(trim($x)), explode('+', $texto));
                        return in_array(null, $operandos, true) ? null : implode(' + ', $operandos);
                    };
                    // Un rótulo como «Equation:» delimita el lado izquierdo en la fuente.
                    $a = $lado($m[1]);
                    $b = $lado($m[2]);
                    if ($a !== null && $b !== null) {
                        return ucfirst($a) . ' → ' . $b . '.';
                    }
                }
                break;

            case 'contraste':
                if (preg_match('/\bexperimental\s+(\w+)\s+(?:should have|has)\s+all\s+(\w+)\s+(covered|exposed)\s+and\s+(?:the\s+)?control\s+\1\s+(?:should have|has)\s+all\s+\2\s+(covered|exposed)\b/', $t, $m)
                    && $m[3] !== $m[4]) {
                    $objeto = self::traducir($m[1]);
                    $rasgo = self::traducir($m[2]);
                    $a = self::traducir($m[3]);
                    $b = self::traducir($m[4]);
                    if ($objeto && $rasgo && $a && $b) {
                        return "La $objeto experimental tiene todas las $rasgo $a y la $objeto de control tiene todas las $rasgo $b.";
                    }
                }
                break;

            case 'duracion':
                if (preg_match('/\b(?:dura|duracion de(?:l| la)?)\s+([^?]+)/', $q, $tema)
                    && TutorLexico::cobertura($tema[1], $fragmento) < .66) {
                    break;
                }
                if (!preg_match('/\b(?:another|additional|extension|adicionales?|other)\b/', $t)
                    && preg_match('/\b(?:for|durante)\s+' . $numero . '\s*(?:full|completos)?\s*' . $unidad . '\b/', $t, $m)
                    && preg_match('/\b(?:seconds?|minutes?|hours?|days?|segundos?|minutos?|horas?|dias?)\b/', $m[2])) {
                    return 'Dura ' . self::numero($m[1]) . ' ' . self::unidad($m[2]) . '.';
                }
                break;

            case 'minimo':
                if (preg_match('/\b(?:at least|como minimo|minim[oa] de)\s+' . $numero . '\s*' . $unidad . '\b/', $t, $m)
                    && TutorLexico::cobertura($q, $t) >= .45
                    && (!str_contains($q, 'profund') || preg_match('/\b(?:deep|depth|profundidad)\b/', $t))) {
                    return 'La medida mínima es de ' . self::numero($m[1]) . ' ' . self::unidad($m[2]) . '.';
                }
                break;

            case 'espesor':
                if (preg_match('/\b(?:fill|llena)\b.{0,120}?\b(?:to|hasta)\s+(about|approximately|aproximadamente)\s+' . $numero . '\s*' . $unidad . '\b/', $t, $m)) {
                    return 'El espesor es de aproximadamente ' . self::numero($m[2]) . ' ' . self::unidad($m[3]) . '.';
                }
                if (!str_contains($q, 'aproximad') && preg_match('/\b' . $numero . '\s*' . $unidad . '\s+(?:base layer|of thickness|de espesor)\b/', $t, $m)) {
                    return 'El espesor es de ' . self::numero($m[1]) . ' ' . self::unidad($m[2]) . '.';
                }
                break;

            case 'inicio':
                if (!preg_match('/\b(?:repeat|repite|aumenta|increasing)\b/', $t)
                    && preg_match('/\b(?:drop|suelta|deja caer)\b.{0,120}?\b(?:from a height of|desde una altura de)\s+' . $numero . '\s*' . $unidad . '\b/', $t, $m)) {
                    preg_match('/(?:#|numero\s+)\s*(\d+)\b/', $q, $pedido);
                    if (!isset($pedido[1]) || preg_match('/(?:#|numero\s+)\s*' . preg_quote($pedido[1], '/') . '\b/', $t)) {
                        return 'La primera altura de caída es de ' . self::numero($m[1]) . ' ' . self::unidad($m[2]) . '.';
                    }
                }
                break;

            case 'ensayos':
                if (preg_match('/\b(?:complete|perform|completa|realiza)\s+' . $numero . '\s+(?:trials|ensayos)\s+(?:and|then|y|antes de)\s+(?:compute|calculate|calcula|calcular)\s+(?:the\s+|el\s+)?(?:average|promedio)/', $t, $m)) {
                    return 'Completa ' . self::numero($m[1]) . ' ensayos antes de calcular el promedio.';
                }
                break;

            case 'observacion':
                if (!preg_match('/\b(?:daily|every day|cada dia)\b/', $t)
                    || !preg_match('/\b(?:write down|write|record|describe|list)\s+(?:(or)\s+(illustrate|draw|sketch)\s+)?(?:any\s+|the\s+)?([^.!?]+?)(?=\s+(?:they|you|we|on|in|using)\s|[.!?]|$)/', $t, $m)) {
                    break;
                }
                $objetivo = self::traducir($m[3]);
                if (!$objetivo || TutorLexico::cobertura($q, $t) < .25) {
                    break;
                }
                $accion = $m[2] === '' ? 'registra' : 'escribe o ilustra';
                $sujetos = null;
                if (preg_match('/\bboth (\w+)\b/', $t, $grupo)) {
                    $sujetos = self::traducir($grupo[1]);
                } elseif (preg_match('/\beach (\w+)\b/', $t, $grupo)) {
                    $nombre = preg_quote($grupo[1], '/');
                    if (preg_match('/\bcontrol ' . $nombre . '\b/', $fragmento)
                        && preg_match('/\bexperimental ' . $nombre . '\b/', $fragmento)) {
                        $sujetos = self::traducir($grupo[1] . 's');
                    }
                }
                if ($sujetos) {
                    $ambos = str_ends_with($sujetos, 'as') ? 'ambas' : 'ambos';
                    return "Revisa $ambos $sujetos cada día y $accion las $objetivo.";
                }
                return ucfirst($accion) . " las $objetivo cada día.";

            case 'factores':
                if (preg_match('/\bfactors\s+(?:affecting|that affect)\s+[^.!?]+?\s+are\s+([^.!?]+)/', $t, $m)) {
                    $lista = self::traducir($m[1]);
                    if ($lista && TutorLexico::cobertura($q, $t) >= .25) {
                        return 'Los factores son ' . $lista . '.';
                    }
                }
                break;

            case 'medicion':
                if (preg_match('/\bmeasure\s+(?:the\s+)?(\w+)\s+and\s+(?:the\s+)?(\w+)\s+of\s+(?:the\s+)?([^.!?]+)\b/', $t, $m)) {
                    $a = self::traducir($m[1]);
                    $b = self::traducir($m[2]);
                    $objeto = self::traducir($m[3]);
                    if ($a && $b && $objeto && TutorLexico::cobertura($q, $t) >= .25) {
                        return "Mide el $a y la $b del $objeto.";
                    }
                }
                break;
        }
        return null;
    }

    private static function traducir(string $texto): ?string
    {
        // Glosario cerrado de vocabulario escolar; una palabra desconocida detiene la traducción.
        $palabras = [
            'sunlight'=>'luz solar','water'=>'agua','carbon dioxide'=>'dióxido de carbono','oxygen'=>'oxígeno',
            'glucose'=>'glucosa','food'=>'alimento','energy'=>'energía','heat'=>'calor','light'=>'luz','salt'=>'sal',
            'plant'=>'planta','plants'=>'plantas','group'=>'grupo','groups'=>'grupos','sample'=>'muestra','samples'=>'muestras','leaves'=>'hojas','covered'=>'cubiertas',
            'exposed'=>'expuestas','size'=>'tamaño','velocity'=>'velocidad','speed'=>'velocidad','geology'=>'geología',
            'impactor'=>'impactador','target surface'=>'superficie objetivo','surface'=>'superficie','mass'=>'masa',
            'diameter'=>'diámetro','depth'=>'profundidad','crater'=>'cráter','layer'=>'capa','volume'=>'volumen','length'=>'longitud',
            'observations'=>'observaciones','changes'=>'cambios','measurements'=>'mediciones',
            'details'=>'detalles','notes'=>'notas','steps'=>'pasos',
        ];
        $texto = trim($texto, ' .,');
        $texto = preg_replace('/^(?:the|a|an) /', '', $texto);
        if (str_starts_with($texto, 'resulting ')) {
            $objeto = self::traducir(substr($texto, 10));
            return $objeto === null ? null : $objeto . ' resultante';
        }
        if (isset($palabras[$texto])) {
            return $palabras[$texto];
        }
        if (str_starts_with($texto, 'important ')) {
            $objeto = self::traducir(substr($texto, 10));
            return $objeto === null ? null : $objeto . ' importantes';
        }
        // Coordinaciones y complementos, sin asumir un significado para términos desconocidos.
        foreach ([' and '=>' y ', ' or '=>' o ', ' of the '=>' de ', ', '=>', ', ' (' =>' ('] as $separador=>$union) {
            if (!str_contains($texto, $separador)) {
                continue;
            }
            [$a,$b] = explode($separador, $texto, 2);
            $izq = self::traducir($a);
            $der = self::traducir(rtrim($b, ')'));
            if ($izq !== null && $der !== null) {
                return $izq . ($separador === ' of the ' ? (preg_match('/(?:a|ad|ie)(?: |$)/u', $der) ? ' de la ' : ' del ') : $union) . $der . ($separador === ' (' ? ')' : '');
            }
        }
        return null;
    }

    public static function numero(string $valor): string
    {
        $mapa = ['one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,
            'un'=>1,'una'=>1,'uno'=>1,'dos'=>2,'tres'=>3,'cuatro'=>4,'cinco'=>5,'seis'=>6,'siete'=>7,'ocho'=>8,'nueve'=>9,'diez'=>10];
        return (string) ($mapa[$valor] ?? str_replace('.', ',', $valor));
    }

    private static function unidad(string $unidad): string
    {
        return match ($unidad) {
            'days', 'dias' => 'días',
            'day', 'dia' => 'día',
            'minutes', 'minutos' => 'minutos',
            'minute', 'minuto' => 'minuto',
            'seconds', 'segundos' => 'segundos',
            'second', 'segundo' => 'segundo',
            'hours', 'horas' => 'horas',
            'hour', 'hora' => 'hora',
            'centimeter', 'centimeters', 'centimetro', 'centimetros' => 'cm',
            'metre', 'metres', 'meter', 'meters', 'metro', 'metros' => 'm',
            default => $unidad,
        };
    }

    public static function firma(string $texto): string
    {
        $lista = TutorHechosExplicitos::firmaLista($texto);
        if ($lista !== null) {
            return $lista;
        }
        $normal = TutorLexico::normalizar($texto);
        $normal = preg_replace('/\bcomo minimo\b/u', 'al menos', $normal);
        $normal = str_replace(['-->', 'completos', 'completas'], ['->', '', ''], $normal);
        $grupos = [
            'dura' => ['dura', 'duracion'],
            'escribe' => ['escribe', 'escribir', 'anota', 'anotar', 'registra', 'registrar'],
            'completa' => ['completa', 'completar', 'realiza', 'realizar'],
            'promedio' => ['promedio', 'valores promedio', 'valor promedio', 'media'],
            'ilustra' => ['ilustra', 'ilustrar', 'dibuja', 'dibujar'],
            'revisa' => ['revisa', 'revisar', 'observa', 'observar'],
            'cubiertas' => ['cubiertas','tapadas'], 'expuestas'=>['expuestas','descubiertas'],
            'cm' => ['cm', 'centimetro', 'centimetros'],
            'kg' => ['kg', 'kilogramo', 'kilogramos'],
            'km' => ['km', 'kilometro', 'kilometros'],
        ];
        foreach ($grupos as $base => $equivalentes) {
            $normal = preg_replace('/\b(?:' . implode('|', $equivalentes) . ')\b/u', $base, $normal);
        }
        $normal = preg_replace('/^(?:se |los estudiantes |las estudiantes )?deben? (?=revisa|escribe|ilustra|registra|mide|completa)/u', '', $normal);
        $normal = preg_replace_callback('/\b(?:un[oa]?|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez)\b/u',
            static fn (array $m): string => self::numero($m[0]), $normal);
        $normal = preg_replace('/\b(?:dura es|es dura)\b/u', 'dura', $normal);
        $normal = preg_replace('/\b(?:el experimento|experimento|tiene|las|la|el|de|del|los)\b/u', ' ', $normal);
        $normal = preg_replace('/(?<=\d)[.,](?=\d)/u', ',', $normal);
        $normal = preg_replace('/[.!?():;]|,(?![0-9])/u', ' ', $normal);
        return trim(preg_replace('/\s+/u', ' ', $normal) ?? '');
    }
}
