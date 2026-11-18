<?php

declare(strict_types=1);

/** Una proyección verificable de un pasaje, con el mismo formato para la respuesta. */
final class TutorEvidencia
{
    public function __construct(
        public readonly string $tipo,
        public readonly array $valor,
        public readonly string $texto,
        public readonly string $pasaje
    ) {
    }

    public function firma(): string
    {
        return json_encode([$this->tipo, $this->valor], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function normal(string $texto): string
    {
        return trim(TutorLexico::normalizar($texto), " .\t\n");
    }

    /** Los modificadores se conservan, como la diferencia entre luz y luz directa. */
    private static function nominal(string $texto, bool $ingles): ?string
    {
        $texto = preg_replace('/^(?:the|an?|el|la|los|las|un[ao]?) /u', '', self::normal($texto));
        if (!preg_match('/^\p{L}+(?: \p{L}+){0,5}$/u', $texto)) {
            return null;
        }
        if (!$ingles) {
            return match ($texto) {
                'pelo' => 'cabello', 'aguja' => 'indicador', 'goma de mascar' => 'chicle',
                default => $texto,
            };
        }
        $traduccion = TutorLexico::traduccionNominal($texto);
        if ($traduccion !== null) {
            return self::nominal($traduccion, false);
        }

        // Sentidos físicos de vocabulario común. Las oraciones y los vínculos
        // entre entidades se extraen del pasaje, nunca de este vocabulario.
        $fisico = [
            'air' => 'aire', 'wind' => 'viento', 'rain' => 'lluvia', 'sun' => 'sol',
            'end' => 'extremo', 'box' => 'caja', 'clay' => 'arcilla', 'water' => 'agua', 'hair' => 'cabello',
            'chewing gum' => 'chicle', 'head' => 'cabeza', 'weather vane' => 'veleta',
            'instruments' => 'instrumentos', 'pointer' => 'indicador', 'diameter' => 'diámetro', 'length' => 'longitud',
            'width' => 'anchura', 'height' => 'altura', 'area' => 'área',
        ];
        if (isset($fisico[$texto])) {
            return self::normal($fisico[$texto]);
        }
        foreach (['direct ' => ' directo', 'modeling ' => ' de modelar', 'modelling ' => ' de modelar'] as $prefijo => $sufijo) {
            if (str_starts_with($texto, $prefijo)) {
                $base = self::nominal(substr($texto, strlen($prefijo)), true);
                return $base === null ? null : $base . $sufijo;
            }
        }
        return null;
    }

    private static function lista(string $texto, bool $ingles): ?array
    {
        $disyuncion = preg_match($ingles ? '/\bor\b/' : '/\b(?:o|u)\b/', $texto);
        $conjuncion = preg_match($ingles ? '/\band\b/' : '/\b(?:y|e)\b/', $texto);
        if (!$disyuncion && !$conjuncion || $disyuncion && $conjuncion) {
            return null;
        }
        $partes = preg_split($ingles ? '/,?\s+(?:and|or)\s+|,\s*/u' : '/,?\s+(?:y|e|o|u)\s+|,\s*/u', $texto);
        $elementos = [];
        foreach ($partes as $parte) {
            $elemento = self::nominal($parte, $ingles);
            if ($elemento === null) {
                return null;
            }
            $elementos[] = $elemento;
        }
        if (count($elementos) < 2 || count(array_unique($elementos)) !== count($elementos)) {
            return null;
        }
        sort($elementos, SORT_STRING);
        return [$disyuncion ? 'o' : 'y', $elementos];
    }

    private static function responder(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1) . '.';
    }

    /** Selecciona una proyección según lo pedido y conserva el pasaje completo que la prueba. */
    public static function extraer(string $pregunta, string $fragmento): ?self
    {
        $q = self::normal($pregunta);
        $t = self::normal($fragmento);
        if (preg_match('/\b(?:no|nunca|jamas|excepto)\b/', $q)) {
            return null;
        }

        if (preg_match('/\bmateriales?\b/', $q)
            && preg_match_all('/(?:^|[.!?]\s+|\b\d+[.] )use ([a-z ]+?(?: or | and )[a-z ]+?)(?: \([^)]{1,150}\))? to ([a-z]+) ([^.!?]+)/', $t, $hallazgos, PREG_SET_ORDER)) {
            $opciones = [];
            foreach ($hallazgos as $m) {
                $verbo = $m[2] === 'plug' ? 'tapar' : TutorLexico::traduccionNominal($m[2]);
                $objetivo = self::nominal(preg_split('/ of | near | above | below /', $m[3], 2)[0], true);
                if ($verbo === null || !preg_match('/\b' . preg_quote(substr(self::normal($verbo), 0, -2), '/') . '\p{L}*\b/u', $q)
                    || ($objetivo === null || !preg_match('/\b' . preg_quote($objetivo, '/') . '\b/u', $q))
                        && !TutorLexico::coincideEnConsulta($m[3], $q)) {
                    continue;
                }
                $lista = self::lista($m[1], true);
                if ($lista !== null) {
                    $opciones[] = new self('materiales', [...$lista, preg_match('/\bpara (.+?)[?]?$/u', $q, $accion) ? $accion[1] : null], self::responder(implode(' ' . $lista[0] . ' ', $lista[1])), $m[0]);
                }
            }
            return count($opciones) === 1 ? $opciones[0] : null;
        }

        if (preg_match('/\b(?:elementos|que|cuales)\b.*\bproteger\b/', $q)
            && preg_match_all('/\b([a-z ]{2,90}) (?:is supposed to|should|must|can) protect ([a-z ]{2,80}) from ([a-z, ]+?)(?=, but\b|[.!?]|$)/', $t, $hallazgos, PREG_SET_ORDER)) {
            $opciones = [];
            foreach ($hallazgos as $m) {
                $sujeto = self::nominal(substr($m[1], strrpos($m[1], ' ') + 1), true);
                if ($sujeto === null || !preg_match('/\b' . preg_quote($sujeto, '/') . '\b/u', $q)
                    || TutorLexico::cobertura($q, $m[0] . ' ' . $t) < .20) {
                    continue;
                }
                $lista = self::lista($m[3], true);
                $objeto = self::nominal(substr($m[2], strrpos($m[2], ' ') + 1), true);
                if ($lista !== null && $objeto !== null && preg_match('/\b' . preg_quote($objeto, '/') . '\b/u', $q)) {
                    $articulo = str_ends_with($sujeto, 'a') ? 'la' : 'el';
                    $referencia = null;
                    if (preg_match('/\b' . preg_quote($sujeto, '/') . ' (?:del|de la) (\p{L}+)[?]?$/u', $q, $tema)
                        && preg_match('/\bbuild (?:a|an|the) ([a-z ]+?) (?:to|for) /', $t, $titulo)
                        && TutorLexico::cobertura($tema[1], $titulo[1]) === 1.0) {
                        $referencia = $tema[1];
                    }
                    $opciones[] = new self('proteccion', [...$lista, $sujeto, $objeto, $referencia],
                        self::responder("$articulo $sujeto debe proteger $objeto de " . implode(', ', array_slice($lista[1], 0, -1))
                            . ' ' . $lista[0] . ' ' . end($lista[1])), $m[0]);
                }
            }
            return count($opciones) === 1 ? $opciones[0] : null;
        }

        if (preg_match('/\b(?:direccion|apunta|orienta)\b/', $q)
            && preg_match_all('/(?:^|[.!?] )([a-z ]{2,100}?) (?:will )?points? (?:to|toward) (?:the direction from which|where) ([a-z ]+?) (?:is (?:blowing|flowing)|comes from)(?=[.!?]|$)/', $t, $hallazgos, PREG_SET_ORDER)) {
            $opciones = [];
            foreach ($hallazgos as $m) {
                $partes = explode(' of ', $m[1], 2);
                $actor = self::nominal($partes[0], true);
                $referencia = isset($partes[1]) ? self::nominal($partes[1], true) : null;
                if (!$actor || !preg_match('/\b' . preg_quote($actor, '/') . '\b/', $q)
                    || isset($partes[1]) && (!$referencia || !preg_match('/\b' . preg_quote($referencia, '/') . '\b/', $q))) {
                    continue;
                }
                $flujo = self::nominal($m[2], true);
                if ($flujo !== null) {
                    $opciones[] = new self('orientacion', ['origen', $flujo, $actor, $referencia], self::responder("hacia donde viene el $flujo"), $m[0]);
                }
            }
            return count($opciones) === 1 ? $opciones[0] : null;
        }
        if (preg_match('/\b(?:calcula|calcular|formula|convierte)\b/', $q)
            && preg_match('/(?:^|[.:] )(multiply|divide) ([a-z0-9]+) (?:by) (?:the )?([a-z]+) \(in ([a-z ]+)\)(?: of (?:your |the )?([a-z ]+?))?(?: \([^)]*\))?\.? (?:[a-z0-9]+\. )?(multiply|divide) the result by (\d+(?:[.,]\d+)?)[.] this is (?:the )?(approximate |estimated )?([a-z ]+) in ([a-z ]+)(?=[.]|$)/', $t, $m)
            && TutorLexico::coincideEnConsulta($m[9], $q)) {
            $magnitud = self::nominal($m[3], true);
            $unidad = self::unidad($m[4]);
            $salida = self::unidad($m[10]);
            $equipo = $m[5] === '' ? null : self::nominal($m[5], true);
            if ($magnitud !== null && $unidad !== null && $salida !== null && ($m[5] === '' || $equipo !== null)) {
                $valor = [$m[2], $m[1] === 'multiply' ? '×' : '÷', $magnitud, $unidad,
                    $m[6] === 'multiply' ? '×' : '÷', self::decimal($m[7]), $salida, $m[8] !== '', $equipo, $m[9]];
                $texto = self::responder(($valor[7] ? 'resultado aproximado: ' : '')
                    . "({$valor[0]} {$valor[1]} $magnitud en $unidad) {$valor[4]} " . str_replace('.', ',', $valor[5]) . ", en $salida");
                return new self('operaciones', $valor, $texto, $m[0]);
            }
        }

        if (preg_match('/\bpor que\b/', $q)
            && preg_match_all('/(?:^|[.!?] |\b\d+[.] )([a-z ]+) will (?:expand and )?(lengthen|shorten) when ([a-z ]+) is (moist|humid|dry), causing ([a-z ]+) to move (down|up)(?=[.]|$)/', $t, $hallazgos, PREG_SET_ORDER)) {
            $opciones = [];
            foreach ($hallazgos as $m) {
                $medio = self::nominal($m[3], true);
                $actor = self::nominal(preg_replace('/^human /', '', $m[1]), true);
                $efecto = self::nominal($m[5], true);
                $estado = $m[4] === 'dry' ? 'seco' : 'humedo';
                $cambio = $m[2] === 'lengthen' ? 'alarga' : 'acorta';
                $sentido = $m[6] === 'down' ? 'baja' : 'sube';
                if ($medio && $actor && $efecto && preg_match('/\b' . preg_quote($efecto, '/') . '\b/', $q)
                    && str_contains($q, "$medio esta $estado") && str_contains($q, $sentido)) {
                    $opciones[] = new self('causa', [$medio, $estado, $actor, $cambio, $efecto, $sentido],
                        self::responder("cuando el $medio está " . ($estado === 'humedo' ? 'húmedo' : $estado)
                            . ", el $actor se $cambio y eso hace que el $efecto " . ($sentido === 'baja' ? 'baje' : 'suba')), $m[0]);
                }
            }
            return count($opciones) === 1 ? $opciones[0] : null;
        }
        return null;
    }

    private static function unidad(string $unidad): ?string
    {
        return match (self::normal($unidad)) {
            'in', 'inch', 'inches', 'pulgada', 'pulgadas' => 'pulgadas',
            'cm', 'centimeters', 'centimetros' => 'centímetros',
            'm', 'meters', 'metres', 'metros' => 'metros',
            'mph', 'mi/h', 'miles per hour', 'millas por hora' => 'millas por hora',
            'km/h', 'kilometers per hour', 'kilometros por hora' => 'kilómetros por hora',
            'm/s', 'meters per second', 'metros por segundo' => 'metros por segundo',
            default => null,
        };
    }

    private static function decimal(string $texto): string
    {
        $partes = explode('.', str_replace(',', '.', $texto), 2);
        $entero = ltrim($partes[0], '0') ?: '0';
        $fraccion = rtrim($partes[1] ?? '', '0');
        return $fraccion === '' ? $entero : "$entero.$fraccion";
    }

    /** Analiza la respuesta completa. Una cita válida no habilita hechos adicionales. */
    public function acepta(string $texto): bool
    {
        $t = self::normal($texto);
        if ($this->tipo === 'materiales') {
            $accion = $this->valor[2];
            $uso = '(?:se pueden? (?:usar|utilizar)|se utilizan?)';
            if ($accion !== null) {
                $proposito = preg_quote($accion, '/');
                // El encabezado solo puede repetir el propósito ya verificado.
                $t = preg_replace('/^los materiales que ' . $uso . ' para ' . $proposito . ' son /u', '', $t);
                $t = preg_replace('/ para ' . $proposito . '$/u', '', $t);
                [$verbo, $objeto] = explode(' ', $accion, 2) + [1 => ''];
                if ($objeto !== '') {
                    $referente = preg_quote($objeto, '/');
                    if (preg_match('/^((?:el|la|los|las) \p{L}+) /u', $objeto, $nombre)) {
                        $referente .= '|' . preg_quote($nombre[1], '/');
                    }
                    $t = preg_replace('/^(?:' . $referente . ') puede ' . preg_quote($verbo, '/') . 'se con /u', '', $t);
                }
            }
            $t = preg_replace('/^(?:' . $uso . '|los materiales (?:son|pueden ser)) /u', '', $t);
            $lista = self::lista($t, false);
            if ($lista === null || $lista[0] !== $this->valor[0]) {
                return false;
            }
            // Las variantes nominales se resuelven contra el elemento de la fuente.
            $elementos = $this->valor[1];
            if (in_array('arcilla de modelar', $elementos, true)) {
                $lista[1] = array_map(static fn (string $elemento): string => match ($elemento) {
                    'arcilla', 'plastilina' => 'arcilla de modelar',
                    default => $elemento,
                }, $lista[1]);
                sort($lista[1], SORT_STRING);
            }
            return $lista[1] === $elementos;
        }
        if ($this->tipo === 'proteccion') {
            $sujeto = preg_quote($this->valor[2], '/');
            $objeto = preg_quote($this->valor[3], '/');
            $referencia = $this->valor[4] === null ? '' : '(?: (?:del|de la) ' . preg_quote($this->valor[4], '/') . ')?';
            $t = preg_replace('/^(?:(?:el|la) ' . $sujeto . $referencia . ' )?(?:protege|debe proteger)(?: (?:los |las |el |la )?' . $objeto . ')? de[l]?\s+/u', '', $t);
            $t = preg_replace('/(?:^|(?<=, )|(?<= y ))de[l]? /u', '', $t);
            return self::lista($t, false) === array_slice($this->valor, 0, 2);
        }
        if ($this->tipo === 'orientacion'
            && preg_match('/^(?:([\p{L} ]{2,70}) (?:apunta|se orienta) )?(?:hacia|en) (?:la direccion |el lugar )?(?:de donde|desde donde|donde|de la que|de la cual) (?:viene|proviene|sopla) (?:el|la) ([\p{L} ]+)$/u', $t, $m)) {
            $sujeto = $this->valor[2] . ($this->valor[3] === null ? '' : ' de la ' . $this->valor[3]);
            return ($m[1] === '' || self::nominal($m[1], false) === $sujeto)
                && self::nominal($m[2], false) === $this->valor[1];
        }
        if ($this->tipo === 'operaciones') {
            $equipo = $this->valor[8];
            $referencia = $equipo === null ? '' : '(?: del ' . preg_quote($equipo, '/') . '| del mismo)?';
            $t = preg_replace('/\brevoluciones por minuto\b(?: \(rpm\))?/u', 'rpm', $t);
            $patron = '/^(?:(.+?) se calcula )?(multiplicar|multiplicando|dividir|dividiendo) (?:los |las |el |la )?([a-z0-9]+)(?: \(\3\))?'
                . $referencia . ' por (?:el|la) ([a-z]+) \(?(?:en|medid[oa] en) ([a-z]+)\)?' . $referencia
                . ',? (?:seguido de|y luego|y despues) (?:(multiplicar|multiplicando|dividir|dividiendo) el resultado )?por (\d+(?:[.,]\d+)?),?'
                . ' (?:obteniendo (?:asi )?(?:el|un) (?:valor|resultado) en|con el resultado en|para obtener el resultado en) ([a-z ]+)$/u';
            if (preg_match($patron, $t, $m)) {
                $aproximada = $m[1] === '' ? $this->valor[7] : preg_match('/\baproximad[oa]\b/', $m[1]) === 1;
                $objetivo = preg_replace('/\b(?:aproximad[oa])\b/u', '', $m[1]);
                if ($m[1] === '' || (!preg_match('/\b(?:no|nunca|sin|jamas)\b/', $objetivo)
                    && count(TutorLexico::terminos($this->valor[9])) === count(TutorLexico::terminos($objetivo))
                    && TutorLexico::coincideEnConsulta($this->valor[9], $objetivo))) {
                    $primera = str_starts_with($m[2], 'multiplic') ? '×' : '÷';
                    $segunda = $m[6] === '' ? $primera : (str_starts_with($m[6], 'multiplic') ? '×' : '÷');
                    return [$m[3], $primera, $m[4], self::unidad($m[5]),
                        $segunda, self::decimal($m[7]), self::unidad($m[8]), $aproximada]
                        === array_slice($this->valor, 0, 8);
                }
            }
            $t = str_replace(['*', ' x ', 'revoluciones por minuto'], ['×', ' × ', 'rpm'], $t);
            if (preg_match('/^(?:(resultado aproximado): )?\(?([a-z0-9]+) *([×÷]) *(?:el |la )?([a-z]+) (?:en|medid[oa] en) ([a-z ]+)\)? *([×÷]) *(\d+(?:[.,]\d+)?),? (?:en |= )([a-z\/ ]+)$/u', $t, $m)) {
                return [$m[2], $m[3], $m[4], self::unidad($m[5]), $m[6], self::decimal($m[7]),
                    self::unidad($m[8]), $m[1] !== ''] === array_slice($this->valor, 0, 8);
            }
        }
        if ($this->tipo === 'causa') {
            $t = str_replace(['pelo', 'aguja', 'debido a que', 'lo que provoca que', 'lo que hace que', 'y eso hace que', 'se hace mas largo', 'descienda', 'desciende', 'baje', 'suba'],
                ['cabello', 'indicador', 'porque', 'y causa que', 'y causa que', 'y causa que', 'se alarga', 'baja', 'baja', 'baja', 'sube'], $t);
            if (preg_match('/^cuando el ([a-z ]+) esta (humedo|seco), (?:el|la) ([a-z ]+) se (alarga|acorta) y causa que (?:el|la) ([a-z ]+) (baja|sube)$/u', $t, $m)) {
                return array_slice($m, 1) === $this->valor;
            }
            if (preg_match('/^(?:el|la) ([a-z ]+) (baja|sube) porque,? cuando el ([a-z ]+) esta (humedo|seco), (?:el|la) ([a-z ]+) se (alarga|acorta)$/u', $t, $m)) {
                return [$m[3], $m[4], $m[5], $m[6], $m[1], $m[2]] === $this->valor;
            }
        }
        return false;
    }
}
