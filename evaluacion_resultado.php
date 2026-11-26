<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/rendicion.php';

exigirRol('alumno');

header('Cache-Control: private, no-store');

$id = $_GET['id'] ?? null;

if (!idEvaluacion($id)) {
    http_response_code(404);
    exit('Resultado no disponible.');
}

try {
    require __DIR__ . '/config/conexion.php';

    // Consultar resultado del estudiante
    $resultado = rendicionPDO($pdo, "
        SELECT
            e.*,
            v.titulo,
            v.aula_id,
            a.nombre AS aula_nombre,
            a.asignatura_nombre,
            round(
                e.puntaje_obtenido * 100
                / NULLIF(e.puntaje_maximo, 0),
                2
            ) AS porcentaje
        FROM entregas e
        JOIN evaluaciones v ON v.id = e.evaluacion_id
        JOIN aulas a ON a.id = v.aula_id
        JOIN usuarios u ON u.id = e.estudiante_id
        WHERE e.id = ?
          AND e.estudiante_id = ?
          AND u.activo
          AND u.rol = 'alumno'
          AND a.colegio_id = ?
          AND u.colegio_id = a.colegio_id
          AND v.publicada_en IS NOT NULL
          AND e.estado = 'calificada'
    ", [
        $id,
        $_SESSION['usuario_id'],
        $_SESSION['colegio_id']
    ])->fetch();

    if (!$resultado) {
        http_response_code(404);
        exit('Resultado no disponible.');
    }

    // Consultar preguntas y respuestas
    $preguntas = rendicionPDO($pdo, '
        SELECT
            p.id AS pregunta,
            p.enunciado,
            p.puntaje,
            a.id AS alternativa,
            a.texto,
            a.es_correcta,
            r.alternativa_id IS NOT NULL AS seleccionada
        FROM preguntas p
        JOIN alternativas a ON a.pregunta_id = p.id
        LEFT JOIN respuestas r
            ON r.alternativa_id = a.id
            AND r.entrega_id = ?
        WHERE p.evaluacion_id = ?
        ORDER BY p.orden, a.orden
    ', [
        $id,
        $resultado['evaluacion_id']
    ])->fetchAll(PDO::FETCH_GROUP);

} catch (Throwable $e) {
    error_log('Error resultado evaluación: ' . $e->getMessage());

    http_response_code(500);
    exit('No se pudo consultar el resultado.');
}

// Datos de presentación

$nombre = trim($_SESSION['nombre'] ?? '');
$primerNombre = explode(' ', $nombre)[0] ?: 'Estudiante';

$asignatura = trim($resultado['asignatura_nombre'] ?? '');

if ($asignatura === '') {
    $asignatura = $resultado['aula_nombre'];
}

$nota = (float) $resultado['calificacion'];
$aprobado = $nota >= 4;

$volver = 'asignatura.php?aula_id='
    . (int) $resultado['aula_id']
    . '&seccion=evaluaciones';

function formatoResultado(float $numero, int $decimales = 0): string
{
    return number_format($numero, $decimales, ',', '.');
}

function valorBooleanoResultado(mixed $valor): bool
{
    return in_array($valor, [true, 1, '1', 't', 'true'], true);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Resultado | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/evaluacion-resultado.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">
</head>

<body class="av-dashboard resultado-page">

    <!-- Encabezado -->

    <header class="resultado-header">

        <a href="mis_cursos.php" class="resultado-marca">
            <img src="img/Logo.png" alt="">
            <span>AulaIA</span>
        </a>

        <div class="resultado-perfil">
            <strong><?= htmlspecialchars($primerNombre, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            <span>Estudiante</span>
        </div>

    </header>

    <main class="resultado-contenido">

        <a class="resultado-volver"
           href="<?= htmlspecialchars($volver, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            &larr; Volver a la asignatura
        </a>

        <div class="resultado-bloque">

            <!-- Información general -->

            <div class="resultado-titulo">

                <span>
                    <?= htmlspecialchars($asignatura, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </span>

                <h1>
                    <?= htmlspecialchars($resultado['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </h1>

                <p class="resultado-confirmacion">

                    <svg viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="2"
                         aria-hidden="true">

                        <circle cx="12" cy="12" r="9"/>
                        <path d="m8 12 3 3 5-6"/>

                    </svg>

                    Entrega confirmada

                </p>

            </div>

            <!-- Resultados -->

            <section class="resultado-resumen">

                <h2>Resultados de la evaluación</h2>

                <p>
                    <strong>Nota obtenida:</strong>

                    <strong class="<?= $aprobado
                        ? 'nota-aprobada'
                        : 'nota-reprobada' ?>">

                        <?= htmlspecialchars(formatoResultado($nota, 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

                    </strong>
                </p>

                <p>
                    <strong>Puntaje obtenido:</strong>

                    <?= htmlspecialchars(
                        (string) $resultado['puntaje_obtenido']
                    , ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

                    de

                    <?= htmlspecialchars(
                        (string) $resultado['puntaje_maximo']
                    , ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

                    puntos
                </p>

                <p>
                    <strong>Porcentaje:</strong>

                    <?= $resultado['porcentaje'] !== null
                        ? htmlspecialchars(formatoResultado((float) $resultado['porcentaje'], 2), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '%'
                        : 'No disponible' ?>
                </p>

            </section>

            <!-- Revisión de respuestas -->

            <section class="resultado-revision">

                <h2>Revisión de respuestas</h2>

                <?php $numero = 0; ?>

                <?php foreach ($preguntas as $opciones): ?>

                    <?php

                    $numero++;
                    $seleccionadas = [];
                    $correctas = [];

                    foreach ($opciones as $opcion) {

                        if (valorBooleanoResultado(
                            $opcion['seleccionada']
                        )) {
                            $seleccionadas[] =
                                (string) $opcion['alternativa'];
                        }

                        if (valorBooleanoResultado(
                            $opcion['es_correcta']
                        )) {
                            $correctas[] =
                                (string) $opcion['alternativa'];
                        }
                    }

                    sort($seleccionadas, SORT_STRING);
                    sort($correctas, SORT_STRING);

                    $acertada = $seleccionadas === $correctas;
                    $puntaje = (string) $opciones[0]['puntaje'];

                    ?>

                    <article class="resultado-pregunta">

                        <div class="resultado-pregunta-header">

                            <strong>
                                Pregunta <?= htmlspecialchars((string) $numero, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </strong>

                            <span class="<?= $acertada
                                ? 'nota-aprobada'
                                : 'nota-reprobada' ?>">

                                <?= $acertada
                                    ? htmlspecialchars($puntaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                                    : '0' ?>

                                /

                                <?= htmlspecialchars($puntaje, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

                                puntos

                            </span>

                        </div>

                        <h3>
                            <?= htmlspecialchars(
                                $opciones[0]['enunciado']
                            , ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </h3>

                        <div class="resultado-alternativas">

                            <?php foreach ($opciones as $i => $opcion): ?>

                                <?php

                                $correcta = valorBooleanoResultado(
                                    $opcion['es_correcta']
                                );

                                $seleccionada = valorBooleanoResultado(
                                    $opcion['seleccionada']
                                );

                                $clase = '';
                                $estado = '';

                                if ($correcta) {

                                    $clase = 'alternativa-correcta';

                                    $estado = $seleccionada
                                        ? 'Respuesta correcta seleccionada'
                                        : 'Respuesta correcta no seleccionada';

                                } elseif ($seleccionada) {

                                    $clase = 'alternativa-incorrecta';
                                    $estado = 'Selección incorrecta';

                                }

                                ?>

                                <div class="resultado-alternativa <?= $clase ?>">

                                    <div class="alternativa-contenido">

                                        <?php if ($correcta): ?>

                                            <svg viewBox="0 0 24 24"
                                                 fill="none"
                                                 stroke="currentColor"
                                                 stroke-width="2"
                                                 aria-hidden="true">

                                                <circle cx="12" cy="12" r="9"/>
                                                <path d="m8 12 3 3 5-6"/>

                                            </svg>

                                        <?php elseif ($seleccionada): ?>

                                            <svg viewBox="0 0 24 24"
                                                 fill="none"
                                                 stroke="currentColor"
                                                 stroke-width="2"
                                                 aria-hidden="true">

                                                <circle cx="12" cy="12" r="9"/>
                                                <path d="m9 9 6 6m0-6-6 6"/>

                                            </svg>

                                        <?php else: ?>

                                            <span class="alternativa-neutra"
                                                  aria-hidden="true"></span>

                                        <?php endif; ?>

                                        <span>

                                            <?= htmlspecialchars(
                                                ($i < 26
                                                    ? chr(65 + $i)
                                                    : (string) ($i + 1))
                                                . '. '
                                                . $opcion['texto']
                                            , ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>

                                        </span>

                                    </div>

                                    <?php if ($estado !== ''): ?>

                                        <small>
                                            <?= htmlspecialchars($estado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                                        </small>

                                    <?php endif; ?>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if (!$seleccionadas): ?>

                            <p class="resultado-sin-respuesta">
                                No respondiste esta pregunta.
                            </p>

                        <?php endif; ?>

                    </article>

                <?php endforeach; ?>

            </section>

            <!-- Volver -->

            <div class="resultado-acciones">

                <a href="<?= htmlspecialchars($volver, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   class="resultado-boton">

                    Volver a la asignatura

                </a>

            </div>

        </div>

    </main>

</body>
</html>