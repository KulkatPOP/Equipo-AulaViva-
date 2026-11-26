<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/rendicion.php';

exigirRol('alumno');

header('Cache-Control: private, no-store');

// Respuesta del servidor

function responderRendicion(int $codigo, array $datos): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode($datos, JSON_THROW_ON_ERROR);

    exit;
}

// Parámetros de acceso

$id = $_GET['id'] ?? null;

$aulaId = filter_input(
    INPUT_GET,
    'aula_id',
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
            'max_range' => 2147483647
        ]
    ]
);

$metodo = $_SERVER['REQUEST_METHOD'];

if (!in_array($metodo, ['GET', 'POST'], true)) {

    header('Allow: GET, POST');
    http_response_code(405);

    exit;
}

if (!idEvaluacion($id) || !$aulaId) {

    http_response_code(400);

    exit('Solicitud inválida.');
}

$alumno = (int) $_SESSION['usuario_id'];
$colegio = (int) $_SESSION['colegio_id'];

// Validación de solicitudes

if ($metodo === 'POST') {

    if (
        !isset($_SESSION['csrf_rendicion'])
        || !hash_equals(
            $_SESSION['csrf_rendicion'],
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''
        )
    ) {
        responderRendicion(403, [
            'error' => 'Vuelve a abrir la evaluación para continuar.'
        ]);
    }

    $entrada = file_get_contents(
        'php://input',
        false,
        null,
        0,
        1048577
    );

    $datos = is_string($entrada)
        && strlen($entrada) <= 1048576
        ? json_decode($entrada, true)
        : null;

    $accion = $datos['accion'] ?? null;

    if (
        !is_array($datos)
        || !in_array(
            $accion,
            ['iniciar', 'estado', 'guardar', 'entregar', 'ayuda'],
            true
        )
    ) {
        responderRendicion(422, [
            'error' => 'La solicitud no es válida.'
        ]);
    }

    session_write_close();
}

// Consultar evaluación

try {

    require __DIR__ . '/config/conexion.php';

    if ($metodo === 'POST') {
        $pdo->beginTransaction();
    }

    $evaluacion = rendicionPDO(
        $pdo,
        "
        SELECT
            e.titulo,
            e.aula_id,
            COALESCE(
                NULLIF(BTRIM(a.asignatura_nombre), ''),
                a.nombre
            ) AS asignatura
        FROM evaluaciones e
        JOIN aulas a ON a.id = e.aula_id
        JOIN usuarios u ON u.colegio_id = a.colegio_id
        WHERE e.id = ?
            AND a.id = ?
            AND a.colegio_id = ?
            AND u.id = ?
            AND u.activo
            AND u.rol = 'alumno'
            AND e.publicada_en IS NOT NULL
        " . ($metodo === 'POST'
            ? ' FOR SHARE OF e, a, u'
            : ''),
        [
            $id,
            $aulaId,
            $colegio,
            $alumno
        ]
    )->fetch();

    if (!$evaluacion) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        responderRendicion(404, [
            'error' => 'Evaluación no disponible.'
        ]);
    }

    // Comprobar matrícula

    $matricula = aulaEvaluaciones(
        $pdo,
        $aulaId,
        $metodo === 'POST'
    );

    // Consultar intento del estudiante

    $consultaEntrega = '
        SELECT *
        FROM entregas
        WHERE evaluacion_id = ?
            AND estudiante_id = ?
    ';

    $entrega = rendicionPDO(
        $pdo,
        $consultaEntrega
            . ($metodo === 'POST' ? ' FOR UPDATE' : ''),
        [$id, $alumno]
    )->fetch();

    if (
        !$matricula
        && ($entrega['estado'] ?? '') !== 'calificada'
    ) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        responderRendicion(403, [
            'error' => 'No tienes acceso a esta evaluación.'
        ]);
    }

    // Procesar acciones

    if ($metodo === 'POST') {

        // Iniciar evaluación

        if ($accion === 'iniciar' && !$entrega) {

            iniciarRendicion(
                $pdo,
                $id,
                $alumno
            );

            $entrega = rendicionPDO(
                $pdo,
                $consultaEntrega . ' FOR UPDATE',
                [$id, $alumno]
            )->fetch();
        }

        // Validar intento

        if (
            !$entrega
            || (
                $accion !== 'iniciar'
                && ($datos['entrega'] ?? null) !== $entrega['id']
            )
        ) {

            $pdo->rollBack();

            responderRendicion(404, [
                'error' => 'Intento no disponible.'
            ]);
        }

        // Consultar estado

        $estado = rendicionEstado(
            $pdo,
            $entrega['id']
        );

        // Finalizar automáticamente al vencer el tiempo

        if (
            $estado['estado'] === 'en_progreso'
            && $estado['restante_ms'] <= 0
        ) {

            cerrarRendicion($pdo, $entrega);

            $estado = rendicionEstado(
                $pdo,
                $entrega['id']
            );
        }

        $codigo = 200;
        $extra = [];

        // Solicitar ayuda

        if ($accion === 'ayuda') {

            $solicitud = $datos['solicitud'] ?? null;
            $mensaje = $datos['mensaje'] ?? null;

            if (
                !idEvaluacion($solicitud)
                || !is_string($mensaje)
                || str_contains($mensaje, "\0")
                || preg_match(
                    '/\A.{0,500}\z/us',
                    $mensaje
                ) !== 1
            ) {

                $pdo->rollBack();

                responderRendicion(422, [
                    'error' => 'Revisa el mensaje de ayuda.'
                ]);
            }

            rendicionPDO(
                $pdo,
                '
                INSERT INTO solicitudes_ayuda (
                    id,
                    entrega_id,
                    mensaje
                )
                VALUES (?, ?, ?)
                ON CONFLICT (id) DO NOTHING
                ',
                [
                    $solicitud,
                    $entrega['id'],
                    $mensaje
                ]
            );

            $solicitudValida = rendicionPDO(
                $pdo,
                '
                SELECT 1
                FROM solicitudes_ayuda
                WHERE id = ?
                    AND entrega_id = ?
                ',
                [
                    $solicitud,
                    $entrega['id']
                ]
            )->fetchColumn();

            if (!$solicitudValida) {

                $pdo->rollBack();

                responderRendicion(409, [
                    'error' => 'Identificador de solicitud en uso.'
                ]);
            }

            $extra['ayuda_recibida'] = true;

        // Guardar o entregar respuestas

        } elseif (
            in_array($accion, ['guardar', 'entregar'], true)
            && $estado['estado'] === 'en_progreso'
        ) {

            $selecciones = $datos['selecciones'] ?? null;

            if (
                !is_array($selecciones)
                || !array_is_list($selecciones)
            ) {

                $pdo->rollBack();

                responderRendicion(422, [
                    'error' => 'Revisa las alternativas seleccionadas.'
                ]);
            }

            // Validar identificadores

            foreach ($selecciones as $opcion) {

                if (
                    !is_string($opcion)
                    || preg_match(
                        '/\A[1-9][0-9]{0,18}\z/',
                        $opcion
                    ) !== 1
                    || (
                        strlen($opcion) === 19
                        && strcmp(
                            $opcion,
                            '9223372036854775807'
                        ) > 0
                    )
                ) {

                    $pdo->rollBack();

                    responderRendicion(422, [
                        'error' => 'Alternativa inválida.'
                    ]);
                }
            }

            // Eliminar duplicados

            $selecciones = array_values(
                array_unique($selecciones)
            );

            // Comprobar revisión de respuestas

            if (
                ($datos['revision'] ?? null)
                !== (int) $estado['revision_respuestas']
            ) {

                $codigo = 409;

                $extra['error'] =
                    'Otra pestaña guardó respuestas más recientes.';

            } else {

                // Guardar respuestas

                $guardadas = guardarRespuestasRendicion(
                    $pdo,
                    $entrega,
                    $selecciones
                );

                if (
                    !$guardadas
                    && rendicionEstado(
                        $pdo,
                        $entrega['id']
                    )['restante_ms'] > 0
                ) {

                    $pdo->rollBack();

                    responderRendicion(422, [
                        'error' => 'Revisa las alternativas seleccionadas y el tipo de pregunta.'
                    ]);
                }

                // Finalizar evaluación

                if (!$guardadas || $accion === 'entregar') {

                    cerrarRendicion(
                        $pdo,
                        $entrega
                    );
                }
            }

            $estado = rendicionEstado(
                $pdo,
                $entrega['id']
            );
        }

        // Entregar preguntas durante un intento activo

        if (
            in_array($accion, ['iniciar', 'estado'], true)
            && $estado['estado'] === 'en_progreso'
            && $estado['restante_ms'] > 0
            && $matricula
        ) {

            $extra['preguntas'] = rendicionPDO(
                $pdo,
                "
                SELECT
                    p.id AS pregunta,
                    p.enunciado,
                    p.puntaje,
                    a.id::text AS alternativa,
                    a.texto,
                    CASE
                        WHEN p.tipo_respuesta = 'multiple'
                        THEN 1
                        ELSE 0
                    END AS multiple
                FROM preguntas p
                JOIN alternativas a
                    ON a.pregunta_id = p.id
                WHERE p.evaluacion_id = ?
                ORDER BY p.orden, a.orden
                ",
                [$id]
            )->fetchAll(PDO::FETCH_GROUP);
        }

        $pdo->commit();

        responderRendicion(
            $codigo,
            $estado + $extra
        );
    }

    // Seguridad de sesión

    $_SESSION['csrf_rendicion'] ??= bin2hex(
        random_bytes(32)
    );

} catch (Throwable $e) {

    if (
        isset($pdo)
        && $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    responderRendicion(500, [
        'error' => 'No se pudo procesar la evaluación.'
    ]);
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="utf-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Rendir evaluación | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">

    <link rel="stylesheet" href="css/dashboard.css">

    <link rel="stylesheet" href="css/evaluacion-rendir.css">

    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">

    <script src="js/evaluacion-rendir.js" defer></script>

</head>

<body class="av-dashboard av-rendicion">

    <!-- Encabezado -->

    <header class="rendicion-header">

        <div class="rendicion-marca">

            <img src="img/Logo.png" alt="">

            <span>AulaIA</span>

        </div>

        <div class="rendicion-usuario">

            <strong>

                <?= escaparMaterial(
                    $_SESSION['nombre'] ?? 'Estudiante'
                ) ?>

            </strong>

            <span>Estudiante</span>

        </div>

    </header>

    <main class="rendicion-contenido">

        <a class="rendicion-volver"
           id="volver-rendicion"
           href="evaluaciones.php?aula_id=<?= $aulaId ?>&amp;id=<?= escaparMaterial($id) ?>">

            &larr; Volver a la evaluación

        </a>

        <div id="rendicion"
             data-csrf="<?= escaparMaterial(
                 $_SESSION['csrf_rendicion']
             ) ?>"
             data-clave="<?= escaparMaterial(
                 "us03:$colegio:$alumno:$id"
             ) ?>"
             data-entrega="<?= escaparMaterial(
                 $entrega['id'] ?? ''
             ) ?>">

            <!-- Título y temporizador -->

            <div class="rendicion-titulo">

                <div>

                    <span class="rendicion-asignatura">

                        <?= escaparMaterial(
                            $evaluacion['asignatura']
                        ) ?>

                    </span>

                    <h1>

                        <?= escaparMaterial(
                            $evaluacion['titulo']
                        ) ?>

                    </h1>

                </div>

                <div class="rendicion-reloj"
                     id="contenedor-reloj"
                     hidden>

                    <span>Tiempo restante</span>

                    <strong id="tiempo-rendicion"
                            role="timer"></strong>

                </div>

            </div>

            <!-- Confirmación de inicio -->

            <section class="rendicion-card"
                     id="inicio-rendicion">

                <h2>Antes de comenzar</h2>

                <div class="rendicion-informacion">

                    <p>

                        <strong>Duración máxima:</strong>
                        60 minutos

                    </p>

                    <p>

                        <strong>Intentos permitidos:</strong>
                        1 intento

                    </p>

                </div>

                <p>

                    El tiempo comenzará a correr cuando inicies
                    la evaluación, sin superar la hora de cierre.

                </p>

                <button class="rendicion-boton"
                        id="iniciar"
                        type="button"
                        hidden>

                    Iniciar evaluación

                </button>

            </section>

            <p id="estado-rendicion"
               role="status"></p>

            <!-- Progreso -->

            <div id="contenedor-progreso"
                 class="rendicion-progreso"
                 hidden>

                <p id="progreso-rendicion"></p>

                <progress id="barra-rendicion"
                          value="0"
                          max="1"></progress>

            </div>

            <!-- Preguntas -->

            <form id="respuestas" hidden>

                <fieldset id="preguntas-rendicion">

                    <legend class="rendicion-sr">

                        Preguntas de la evaluación

                    </legend>

                </fieldset>

                <div class="rendicion-acciones">

                    <button class="rendicion-boton"
                            type="submit"
                            id="enviar-rendicion">

                        Finalizar evaluación

                    </button>

                </div>

            </form>

            <!-- Confirmación de entrega -->

            <dialog id="confirmar-rendicion"
                    aria-labelledby="advertencia-rendicion">

                <h2>Finalizar evaluación</h2>

                <p id="advertencia-rendicion">

                    Tienes preguntas sin contestar.

                    ¿Deseas enviar de todas formas?

                </p>

                <form method="dialog"
                      class="rendicion-acciones">

                    <button class="rendicion-secundario"
                            value="cancelar"
                            autofocus>

                        Cancelar

                    </button>

                    <button class="rendicion-boton"
                            value="enviar">

                        Enviar evaluación

                    </button>

                </form>

            </dialog>

            <!-- Ayuda -->

            <details id="ayuda-rendicion"
                     class="rendicion-card"
                     hidden>

                <summary>Contactar al profesor</summary>

                <form id="solicitud-rendicion">

                    <label for="mensaje-rendicion">

                        Describe tu problema (opcional)

                    </label>

                    <textarea id="mensaje-rendicion"
                              maxlength="500"
                              rows="3"></textarea>

                    <button class="rendicion-boton"
                            type="submit">

                        Solicitar ayuda

                    </button>

                </form>

                <p id="estado-ayuda"
                   role="status"></p>

            </details>

        </div>

    </main>

</body>

</html>