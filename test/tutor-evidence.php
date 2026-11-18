<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/tutor/TutorService.php';

function runTutorEvidenceChecks(?callable $reportar = null): int
{
    $fallos = 0;
    $comprobar = static function (string $nombre, bool $resultado) use (&$fallos, $reportar): void {
        if ($reportar === null) {
            echo ($resultado ? 'OK ' : 'FALLO ') . $nombre . PHP_EOL;
        } else {
            $reportar($nombre, $resultado);
        }
        $fallos += (int) !$resultado;
    };

    $gate = new EvidenceGate();
    foreach ([
        'instrumento' => ['¿Qué instrumento mide el diámetro?', 'A caliper measures diameter.'],
        'lista' => ['¿Qué elementos incluye el kit?', 'El kit incluye cinta, tijeras y papel.'],
        'materiales' => ['¿De qué materiales puede ser el filtro?', 'El filtro puede ser de algodón o lino.'],
        'dirección' => ['¿Hacia dónde apunta la aguja?', 'La aguja apunta hacia el norte.'],
        'cantidad' => ['¿Cuántas botellas requiere el ejercicio?', 'El ejercicio requiere 4 botellas.'],
        'mínimo sin unidad' => ['¿Cuántas fichas como mínimo requiere el juego?', 'El juego requiere al menos 3 fichas.'],
        'fórmula' => ['¿Cómo se calcula el área?', 'El área se calcula multiplicando la base por la altura.'],
        'procedimiento' => ['¿Cómo se prepara la mezcla?', 'Para preparar la mezcla, primero agrega sal al agua y luego revuelve.'],
        'causalidad' => ['¿Por qué se dilata el metal?', 'El metal se dilata porque aumenta su temperatura.'],
        'inventario bilingüe' => ['¿Cuántas monedas se requieren para preparar la muestra?', "Prepare the sample\nMaterials\nTwo coins - new\nUse clean tools."],
        'traducción' => ['¿Qué elemento requiere el equipo?', 'The kit requires helium.'],
    ] as $nombre => [$pregunta, $texto]) {
        $comprobar($nombre, (bool) $gate->preparar($pregunta, [
            ['fragment_id' => 108, 'material_id' => 12, 'page' => 2, 'text' => $texto],
        ], 3));
    }

    $pruebasSinEvidencia = [
        'cantidad sin entidad' => ['¿Cuántas fichas requiere el juego?', 'El juego requiere 4 dados.'],
        'límite contrario' => ['¿Cuántas fichas como mínimo requiere el juego?', 'El juego requiere como máximo 3 fichas.'],
        'resultado no observado' => ['¿Cuántas piezas se obtuvieron?', 'El ejercicio requiere 4 piezas.'],
        'inventario opcional' => ['¿Cuántas monedas se requieren para preparar la muestra?', "Prepare the sample\nMaterials\nTwo coins - optional"],
        'cantidad fuera del inventario' => ['¿Cuántas monedas se requieren para preparar la muestra?', "Prepare the sample\nMaterials\nGloves\nProcedure\nTwo coins - residual"],
        'hipótesis' => ['¿Por qué se dilata el metal?', 'El metal se dilata porque quizas aumenta su temperatura.'],
    ];
    foreach ($pruebasSinEvidencia as $nombre => [$pregunta, $texto]) {
        $comprobar($nombre, !$gate->preparar($pregunta, [
            ['fragment_id' => 108, 'material_id' => 12, 'page' => 2, 'text' => $texto],
        ], 3));
    }

    $refl = new ReflectionClass(TutorService::class);
    $servicio = $refl->newInstanceWithoutConstructor();
    $refl->getProperty('maxRespuesta')->setValue($servicio, 1000);
    $validar = $refl->getMethod('validar');
    foreach ([
        'paráfrasis' => [true, 'El experimento dura cinco días completos.', 'Dura 5 días.', '[F1, p. 2]'],
        'orden de lista' => [true, 'El kit incluye hilo, cinta y tijeras.', 'El kit contiene tijeras, hilo y cinta.', '[F1, p. 2]'],
        'conjunción alterada' => [false, 'El kit incluye hilo, cinta y tijeras.', 'El kit contiene tijeras, hilo o cinta.', '[F1, p. 2]'],
        'lista con dato extra' => [false, 'El kit incluye hilo, cinta y tijeras.', 'El kit incluye hilo, cinta, papel y tijeras.', '[F1, p. 2]'],
        'mínimo equivalente' => [true, 'Al menos 3 fichas.', 'Como mínimo tres fichas.', '[F1, p. 2]'],
        'mínimo omitido' => [false, 'Al menos 3 fichas.', '3 fichas.', '[F1, p. 2]'],
        'unidad equivalente' => [true, 'La bolsa pesa 3 kg.', 'La bolsa pesa tres kilogramos.', '[F1, p. 2]'],
        'unidad sustituida' => [false, 'La bolsa pesa 3 kg.', 'La bolsa pesa 3 g.', '[F1, p. 2]'],
        'instrumento en oración completa' => [true, 'Microscopio.', 'El documento propone el microscopio como instrumento para observar bacterias.', '[F1, p. 2]', '¿Qué instrumento propone el documento para observar bacterias?'],
        'propósito sustituido' => [false, 'Microscopio.', 'El documento propone el microscopio como instrumento para observar estrellas.', '[F1, p. 2]', '¿Qué instrumento propone el documento para observar bacterias?'],
        'cantidad en oración completa' => [true, '2 monedas.', 'Dos monedas se requieren para preparar la muestra.', '[F1, p. 2]', '¿Cuántas monedas se requieren para preparar la muestra?'],
        'cantidad en oración completa alterada' => [false, '2 monedas.', '3 monedas se requieren para preparar la muestra.', '[F1, p. 2]', '¿Cuántas monedas se requieren para preparar la muestra?'],
        'número alterado' => [false, 'El experimento dura cinco días completos.', 'Dura 10 días.', '[F1, p. 2]'],
        'negación' => [false, 'Dura 5 días.', 'No dura 5 días.', '[F1, p. 2]'],
        'relación invertida' => [false, 'El aumento de presión causa el descenso.', 'El descenso causa el aumento de presión.', '[F1, p. 2]'],
        'entidad sustituida' => [false, 'El sensor mide la temperatura.', 'El sensor mide la humedad.', '[F1, p. 2]'],
        'dato externo' => [false, 'Dura 5 días.', 'Dura 5 días y comienza el lunes.', '[F1, p. 2]'],
        'cita falsa' => [false, 'Dura 5 días.', 'Dura 5 días.', '[F3, p. 2]'],
        'página falsa' => [false, 'Dura 5 días.', 'Dura 5 días.', '[F1, p. 1]'],
    ] as $nombre => $caso) {
        [$esperado, $hecho, $text, $citation] = $caso;
        $contexto = ['F1' => ['page' => 2, 'fragment_id' => 108, 'facts' => [$hecho]]];
        $respuesta = new AIResponse(json_encode(['claims' => [compact('text', 'citation')]], JSON_THROW_ON_ERROR), true);
        $comprobar($nombre, ($validar->invoke($servicio, $respuesta, $contexto, $caso[4] ?? '') !== null) === $esperado);
    }
    // Las mismas estructuras se leen del pasaje y de la afirmación del modelo.
    $estructuradas = [
        ['alternativas', '¿Qué materiales se usan para taponar el extremo del tubo?',
            'Use modeling clay or chewing gum to plug the end of the tube.',
            'Chicle o arcilla de modelar.', ['Chicle.', 'Chicle, arcilla de modelar y plástico.', 'Arcilla de modelar y chicle.']],
        ['orientación', '¿En qué dirección apunta la cabeza de la veleta?',
            'The head of the weather vane will point to the direction from which the wind is blowing.',
            'La cabeza de la veleta apunta hacia la dirección de donde proviene el viento.',
            ['La cola de la veleta apunta hacia donde viene el viento.', 'Hacia donde va el viento.', 'Hacia donde viene el río.']],
        ['operaciones', '¿Cómo se calcula la velocidad aproximada del viento?',
            'Multiply rpm by the diameter (in inches). Multiply the result by 0.003. This is the approximate wind speed in miles per hour.',
            'Resultado aproximado: revoluciones por minuto × diámetro medido en pulgadas × 0.003, en mph.',
            ['Resultado aproximado: rpm × diámetro en pulgadas × 0,03, en mph.',
                'Resultado aproximado: rpm ÷ diámetro en pulgadas × 0,003, en mph.',
                'Resultado aproximado: rpm × diámetro en centímetros × 0,003, en mph.']],
        ['cadena causal', '¿Por qué baja el indicador cuando el aire está húmedo?',
            'Human hair will lengthen when the air is humid, causing the pointer to move down.',
            'El indicador desciende porque, cuando el aire está húmedo, el cabello se hace más largo.',
            ['Cuando el aire está seco, el cabello se alarga y eso hace que el indicador baje.',
                'Cuando el aire está húmedo, el cabello se alarga.',
                'Cuando el aire está húmedo, el cabello se acorta y eso hace que el indicador baje.']],
    ];
    foreach ($estructuradas as [$nombre, $pregunta, $fuente, $valida, $invalidas]) {
        $contexto = $gate->preparar($pregunta, [['fragment_id' => 27, 'material_id' => 12, 'page' => 3, 'text' => $fuente]], 3);
        $comprobar($nombre . ' con estructura', isset($contexto['F1']['estructura']));
        $verificar = static function (string $text, string $citation = '[F1, p. 3]') use ($validar, $servicio, $contexto, $pregunta): bool {
            $respuesta = new AIResponse(json_encode(['claims' => [compact('text', 'citation')]], JSON_THROW_ON_ERROR), true);
            return $validar->invoke($servicio, $respuesta, $contexto, $pregunta) !== null;
        };
        $comprobar($nombre . ' paráfrasis', $verificar($valida));
        foreach ($invalidas as $idx => $invalida) {
            $comprobar($nombre . ' rechaza alteración ' . ($idx + 1), !$verificar($invalida));
        }
        $comprobar($nombre . ' cita ajena', !$verificar($valida, '[F2, p. 3]'));
        $comprobar($nombre . ' página ajena', !$verificar($valida, '[F1, p. 4]'));
    }


    // Regresiones del análisis posterior con propósitos, sujetos y magnitudes distintos.
    foreach ([
        ['OR y propósito', '¿Qué materiales se pueden usar para tapar el extremo del recipiente?',
            'Use modeling clay or chewing gum to plug the end of the container.',
            ['Los materiales que se pueden usar para tapar el extremo del recipiente son el chicle o la arcilla de modelar.',
                'Se puede usar arcilla o chicle.', 'El extremo puede taparse con plastilina o chicle.'],
            ['Los materiales que se pueden usar para tapar el extremo del recipiente son arcilla de modelar y chicle.',
                'Para tapar el extremo del recipiente, se puede usar agua o arcilla.', 'Arcilla o agua.',
                'El borde puede taparse con plastilina o chicle.', 'Chicle o arcilla o agua.', 'Plastilina.']],
        ['operación narrada', '¿Cómo se calcula la longitud aproximada?',
            'Multiply rpm by the length (in inches). Multiply the result by 0.003. This is the approximate length in inches.',
            ['La longitud aproximada se calcula multiplicando las revoluciones por minuto (rpm) por la longitud (en pulgadas), y luego multiplicando el resultado por 0.003, obteniendo así un valor en pulgadas.',
                'Multiplicar las revoluciones por minuto por la longitud en pulgadas y luego multiplicar el resultado por 0,0030, con el resultado en pulgadas.',
                'Multiplicar rpm por la longitud en pulgadas y luego por 0,003, con el resultado en pulgadas.'],
            ['Multiplicar rpm por la longitud en pulgadas y luego dividir el resultado por 0,003, con el resultado en pulgadas.',
                'Multiplicar rpm por la longitud en pulgadas y luego multiplicar el resultado por 0,03, con el resultado en pulgadas.',
                'Multiplicar rpm por la longitud en centímetros y luego multiplicar el resultado por 0,003, con el resultado en pulgadas.',
                'Multiplicar rpm por la longitud en pulgadas y luego multiplicar el resultado por 0,003, con el resultado en centímetros.',
                'Multiplicar rpm por la longitud en pulgadas y luego multiplicar el resultado por 0,003.',
                'Resultado aproximado: rpm × longitud en pulgadas × 0,003, en pulgadas, con un margen de 5 %.']],
    ] as [$nombre, $pregunta, $fuente, $validas, $invalidas]) {
        $contexto = $gate->preparar($pregunta, [['fragment_id' => 27, 'material_id' => 12, 'page' => 3, 'text' => $fuente]], 3);
        $comprobar($nombre . ' con estructura', isset($contexto['F1']['estructura']));
        foreach ([true => $validas, false => $invalidas] as $esperado => $afirmaciones) {
            foreach ($afirmaciones as $text) {
                $citation = '[F1, p. 3]';
                $respuesta = new AIResponse(json_encode(['claims' => [compact('text', 'citation')]], JSON_THROW_ON_ERROR), true);
                $comprobar($nombre . ' / ' . $text, ($validar->invoke($servicio, $respuesta, $contexto, $pregunta) !== null) === (bool) $esperado);
            }
        }
    }
    foreach ([
        [true, 'El contenido es 2,5 %.', 'El contenido es 2.5 %.'],
        [false, 'El contenido es 2,5 %.', 'El contenido es 25 %.'],
        [false, 'El contenido es 2,5 %.', 'El contenido es 2,5.'],
        [false, 'El descenso causa el aumento.', 'El aumento causa el descenso.'],
        [true, 'Microscopio.', 'El documento propone un microscopio como instrumento para observar bacterias.'],
        [false, 'Microscopio.', 'El documento propone un microscopio óptico como instrumento para observar bacterias.'],
    ] as [$esperado, $hecho, $text]) {
        $contexto = ['F1' => ['page' => 2, 'fragment_id' => 108, 'facts' => [$hecho]]];
        $citation = '[F1, p. 2]';
        $respuesta = new AIResponse(json_encode(['claims' => [compact('text', 'citation')]], JSON_THROW_ON_ERROR), true);
        $comprobar('comparación estricta / ' . $text, ($validar->invoke($servicio, $respuesta, $contexto,
            '¿Qué instrumento propone el documento para observar bacterias?') !== null) === $esperado);
    }

    return $fallos;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(runTutorEvidenceChecks() === 0 ? 0 : 1);
}
