<?php

require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/notas.php';

exigirRol('alumno');

header('Cache-Control: private, no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Método no permitido.');
}

$aulaId = filter_input(
    INPUT_GET,
    'aula_id',
    FILTER_VALIDATE_INT,
    ['options' => [
        'min_range' => 1,
        'max_range' => 2147483647
    ]]
);

if (!$aulaId) {
    http_response_code(400);
    exit('Asignatura inválida.');
}

$error = false;
$cursos = [];
$asignatura = null;
$materiales = [];
$evaluaciones = [];
$columnasNotas = [];
$promedioNotas = null;

$seccion = $_GET['seccion'] ?? '';

if (!in_array($seccion, ['', 'materiales', 'evaluaciones', 'notas'], true)) {
    http_response_code(400);
    exit('Sección inválida.');
}

try {

    require_once __DIR__ . '/config/conexion.php';

    // Consultar únicamente las asignaturas autorizadas.
    $consulta = consultaAulasMateriales(false);

    $stmt = $pdo->prepare("
        SELECT autorizada.id,
               autorizada.nombre,
               a.asignatura_nombre
        FROM ($consulta) AS autorizada
        JOIN aulas a ON a.id = autorizada.id
        ORDER BY COALESCE(
            NULLIF(BTRIM(a.asignatura_nombre), ''),
            a.nombre
        ), a.id
    ");

    $stmt->execute(parametrosAulasMateriales());

    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Verificar que el estudiante pertenece a la asignatura.
    foreach ($cursos as $curso) {

        if ((int) $curso['id'] === $aulaId) {
            $asignatura = $curso;
            break;
        }

    }

    if (!$asignatura) {
        http_response_code(403);
        exit('No tienes acceso a esta asignatura.');
    }


// Obtener las notas del estudiante.

if ($seccion === 'notas') {

    $stmt = $pdo->prepare("
        SELECT
            ln.id,
            ln.numero,
            ln.tipo,
            ln.evaluacion_id,

            CASE
                WHEN ln.tipo = 'manual'
                    THEN nm.calificacion

                WHEN ln.tipo = 'automatica'
                    THEN COALESCE(
                        an.calificacion,
                        en.calificacion
                    )
            END AS calificacion

        FROM libro_notas ln

        JOIN aulas a
            ON a.id = ln.aula_id

        JOIN aula_alumnos aa
            ON aa.aula_id = a.id

        JOIN usuarios u
            ON u.id = aa.alumno_id
            AND u.colegio_id = a.colegio_id

        LEFT JOIN notas_manuales nm
            ON nm.libro_nota_id = ln.id
            AND nm.aula_id = a.id
            AND nm.estudiante_id = u.id

        LEFT JOIN evaluaciones ev
            ON ev.id = ln.evaluacion_id
            AND ev.aula_id = a.id
            AND ev.publicada_en IS NOT NULL
            AND ev.publicada_en <= CURRENT_TIMESTAMP

        LEFT JOIN entregas en
            ON en.evaluacion_id = ev.id
            AND en.estudiante_id = u.id
            AND en.estado = 'calificada'

        LEFT JOIN ajustes_notas an
            ON an.libro_nota_id = ln.id
            AND an.aula_id = a.id
            AND an.estudiante_id = en.estudiante_id

        WHERE a.id = :aula
            AND u.id = :alumno
            AND a.colegio_id = :colegio
            AND a.activo = TRUE
            AND u.activo = TRUE
            AND u.rol = 'alumno'

        ORDER BY ln.numero ASC, ln.id ASC
    ");

    $stmt->execute([
        'aula' => $aulaId,
        'alumno' => (int) $_SESSION['usuario_id'],
        'colegio' => (int) $_SESSION['colegio_id']
    ]);

    $columnasNotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calcular promedio sin considerar notas pendientes.

    $notasRegistradas = [];

    foreach ($columnasNotas as $columna) {

        if ($columna['calificacion'] !== null) {

            $notasRegistradas[] =
                (float) $columna['calificacion'];
        }
    }

    $promedioNotas = calcularPromedioNotas(
        $notasRegistradas
    );
}

// Evaluaciones publicadas del estudiante

if ($seccion === 'evaluaciones') {

    if (!aulaEvaluaciones($pdo, $aulaId)) {
        http_response_code(403);
        exit('No tienes acceso a esta asignatura.');
    }

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.titulo,
            e.publicada_en,
            e.disponible_desde,
            e.disponible_hasta,
            en.id AS entrega_id,
            en.estado AS estado_entrega
        FROM evaluaciones e
        LEFT JOIN entregas en
            ON en.evaluacion_id = e.id
            AND en.estudiante_id = :alumno
        WHERE e.aula_id = :aula
            AND e.publicada_en IS NOT NULL
        ORDER BY e.disponible_desde DESC NULLS LAST, e.id
    ");

    $stmt->execute([
        'alumno' => (int) $_SESSION['usuario_id'],
        'aula' => $aulaId
    ]);

    $evaluaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener materiales de la asignatura autorizada.

if ($seccion === 'materiales') {

    $consultaMateriales = consultaAulasMateriales(false)
        . ' AND a.id = :aula_id';

    $stmt = $pdo->prepare("
        SELECT m.id, m.titulo
        FROM materiales AS m
        JOIN ($consultaMateriales) AS autorizada
            ON autorizada.id = m.aula_id
        ORDER BY m.id DESC
    ");

    $stmt->execute(
        parametrosAulasMateriales() + [
            ':aula_id' => $aulaId
        ]
    );

    $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
} catch (Throwable $e) {

    error_log('Asignatura estudiante: ' . $e->getMessage());

    http_response_code(500);
    $error = true;

}

$nombre = trim($_SESSION['nombre'] ?? '');
$primerNombre = explode(' ', $nombre)[0] ?: 'Estudiante';

function nombreAsignatura(array $curso): string
{
    $nombre = trim($curso['asignatura_nombre'] ?? '');

    return $nombre !== '' ? $nombre : $curso['nombre'];
}

$nombreAsignatura = $asignatura
    ? nombreAsignatura($asignatura)
    : 'Asignatura';

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title><?= escaparMaterial($nombreAsignatura) ?> | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/docente.css">
    <link rel="stylesheet" href="css/estudiante.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
</head>

<body class="av-dashboard">

    <!-- HEADER ORIGINAL -->

    <header class="av-header">

        <div class="av-header-left">

            <button type="button"
                    class="av-menu-button"
                    id="abrir-menu"
                    aria-label="Abrir menú"
                    aria-controls="menu-lateral"
                    aria-expanded="false">

                <svg viewBox="0 0 24 24"
                     width="24" height="24"
                     fill="none" stroke="currentColor"
                     stroke-width="2" aria-hidden="true">
                    <path d="M3 6h18M3 12h18M3 18h18"/>
                </svg>

            </button>

            <a href="mis_cursos.php" class="av-logo">
                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>
            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">
                <strong><?= escaparMaterial($nombre) ?></strong>
                <span>Estudiante</span>
            </div>

            <div class="av-avatar" aria-hidden="true">
                <svg viewBox="0 0 24 24"
                     width="23" height="23"
                     fill="none" stroke="currentColor"
                     stroke-width="1.7">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                </svg>
            </div>

        </div>

    </header>

    <!-- MENÚ LATERAL DESPLEGABLE -->

    <dialog id="menu-lateral"
            class="av-sidebar"
            aria-labelledby="titulo-menu">

        <div class="av-sidebar-header">

            <h2 id="titulo-menu">AulaIA</h2>

            <button type="button"
                    id="cerrar-menu"
                    class="av-close-button"
                    aria-label="Cerrar menú">
                &times;
            </button>

        </div>

        <nav class="av-sidebar-nav" aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="mis_cursos.php"
               class="av-menu-active"
               aria-current="page">
                Inicio
            </a>

            <span class="av-menu-label">MIS ASIGNATURAS</span>

            <?php if (!$error && $cursos): ?>

                <?php foreach ($cursos as $curso): ?>

                    <a href="asignatura.php?aula_id=<?= (int) $curso['id'] ?>">
                        <?= escaparMaterial(nombreAsignatura($curso)) ?>
                    </a>

                <?php endforeach; ?>

            <?php else: ?>

                <p>No tienes asignaturas inscritas.</p>

            <?php endif; ?>

            <span class="av-menu-label">HERRAMIENTAS</span>

            <a href="tutor.php">Tutor IA</a>

            <a href="mis_cursos.php#resultados">Mis resultados</a>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- CONTENIDO PRINCIPAL -->

    <main class="av-main av-asignatura-main">

    <div class="av-breadcrumb">
        <a href="mis_cursos.php">Inicio</a>
        <span>/</span>
        <span><?= escaparMaterial($nombreAsignatura) ?></span>
    </div>

    <?php if ($error): ?>

        <div class="av-empty" role="alert">
            No se pudo cargar la asignatura.
            Intenta nuevamente.
        </div>

    <?php else: ?>

        <!-- Banner -->

        <section class="av-welcome av-asignatura-banner">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    MI ASIGNATURA
                </span>

                <h1>
                    <?= escaparMaterial($nombreAsignatura) ?>
                </h1>

                <p>
                    Consulta tus materiales, evaluaciones y notas.
                </p>

            </div>

        </section>

<!-- Recursos de la asignatura -->

<section class="av-courses">

    <nav class="av-asignatura-tabs"
         aria-label="Recursos de la asignatura">

        <?php foreach ([
            'materiales' => 'Materiales',
            'evaluaciones' => 'Evaluaciones',
            'notas' => 'Notas'
        ] as $clave => $titulo): ?>

            <a href="asignatura.php?aula_id=<?= $aulaId ?>&amp;seccion=<?= $seccion === $clave ? '' : $clave ?>"
               class="av-asignatura-tab <?= $seccion === $clave ? 'activo' : '' ?>"
               <?= $seccion === $clave ? 'aria-current="page"' : '' ?>>

                <?= escaparMaterial($titulo) ?>

            </a>

        <?php endforeach; ?>

    </nav>

    <?php if ($seccion !== ''): ?>

        <section class="av-recurso-panel">

            <div class="av-recurso-panel-header">

                <h2>
                    <?= match ($seccion) {
                        'materiales' => 'Materiales disponibles',
                        'evaluaciones' => 'Evaluaciones disponibles',
                        'notas' => 'Mis notas'
                    } ?>
                </h2>

                <a href="asignatura.php?aula_id=<?= $aulaId ?>"
                   class="av-recurso-cerrar"
                   aria-label="Cerrar sección">

                    &times;

                </a>

            </div>

            <?php if ($seccion === 'materiales'): ?>

                <?php if (!$materiales): ?>

                    <p class="av-recurso-vacio">
                        Todavía no hay materiales disponibles.
                    </p>

                <?php else: ?>

                    <div class="av-documentos-lista">

                        <?php foreach ($materiales as $material): ?>

                            <article class="av-documento-fila">

                                <svg class="av-documento-icono"
                                     viewBox="0 0 24 24"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="1.6"
                                     stroke-linecap="round"
                                     stroke-linejoin="round"
                                     aria-hidden="true">

                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <path d="M14 2v6h6M8 13h8M8 17h8"/>

                                </svg>

                                <div class="av-documento-info">

                                    <a href="archivo_material.php?material_id=<?= (int) $material['id'] ?>">

                                        <?= escaparMaterial($material['titulo']) ?>

                                    </a>

                                    <span>Documento PDF</span>

                                </div>

                                <a class="av-documento-descargar"
                                   href="archivo_material.php?material_id=<?= (int) $material['id'] ?>&amp;descargar=1">

                                    Descargar

                                </a>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            <?php elseif ($seccion === 'evaluaciones'): ?>

    <?php if (!$evaluaciones): ?>

        <p class="av-recurso-vacio">
            Todavía no hay evaluaciones disponibles.
        </p>

    <?php else: ?>

        <div class="av-documentos-lista">

            <?php foreach ($evaluaciones as $evaluacion): ?>

                <?php

                $estado = estadoDisponibilidadEvaluacion($evaluacion);

                $enlace = 'evaluaciones.php?aula_id='
                    . $aulaId
                    . '&id=' . $evaluacion['id'];

                if ($evaluacion['estado_entrega'] === 'calificada') {

                    $estado = 'realizada';

                    $enlace = 'evaluacion_resultado.php?id='
                        . $evaluacion['entrega_id'];

                } elseif ($evaluacion['entrega_id']) {

                    $estado = 'intento';

                    $enlace = 'evaluacion_rendir.php?aula_id='
                        . $aulaId
                        . '&id=' . $evaluacion['id'];
                }

                $textoEstado = match ($estado) {
                    'disponible' => 'Disponible',
                    'programada' => 'Programada',
                    'finalizada' => 'Finalizada',
                    'realizada' => 'Realizada',
                    'intento' => 'Intento registrado',
                    default => 'No disponible'
                };

                ?>

                <article class="av-documento-fila">

                    <svg class="av-documento-icono av-prueba-icono"
                         viewBox="0 0 24 24"
                         fill="none"
                         stroke="currentColor"
                         stroke-width="1.6"
                         stroke-linecap="round"
                         stroke-linejoin="round"
                         aria-hidden="true">

                        <rect x="5" y="4" width="14" height="18" rx="2"/>
                        <path d="M9 4V2h6v2M9 10h6M9 14h6M9 18h4"/>

                    </svg>

                    <div class="av-documento-info">

                        <a href="<?= escaparMaterial($enlace) ?>">

                            <?= escaparMaterial($evaluacion['titulo']) ?>

                        </a>

                        <span>
                            <strong>Apertura:</strong>

                            <?= escaparMaterial(
                                mostrarFechaEvaluacion(
                                    $evaluacion['disponible_desde']
                                )
                            ) ?>
                        </span>

                        <span>
                            <strong>Cierre:</strong>

                            <?= escaparMaterial(
                                mostrarFechaEvaluacion(
                                    $evaluacion['disponible_hasta']
                                )
                            ) ?>
                        </span>

                    </div>

                    <span class="av-prueba-estado <?= $estado ?>">

                        <?= escaparMaterial($textoEstado) ?>

                    </span>

                </article>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

<?php elseif ($seccion === 'notas'): ?>

    <?php if (!$columnasNotas): ?>

        <p class="av-recurso-vacio">
            Todavía no hay notas registradas.
        </p>

    <?php else: ?>

        <div class="av-notas-contenedor">

            <table class="av-notas-estudiante"
                   aria-label="Calificaciones de la asignatura">

                <thead>

                    <tr>

                        <?php foreach ($columnasNotas as $indice => $columna): ?>

                            <th scope="col">
                                Nota <?= $indice + 1 ?>
                            </th>

                        <?php endforeach; ?>

                        <th scope="col" class="av-notas-promedio">
                            Promedio
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <tr>

                        <?php foreach ($columnasNotas as $columna): ?>

                            <?php
                                $nota = $columna['calificacion'] !== null
                                    ? (float) $columna['calificacion']
                                    : null;
                            ?>

                            <td class="<?= $nota === null
                                ? 'nota-sin-registro'
                                : ($nota < 4.0
                                    ? 'nota-reprobada'
                                    : 'nota-aprobada') ?>">

                                <?= $nota !== null
                                    ? number_format($nota, 1, ',', '.')
                                    : '—' ?>

                            </td>

                        <?php endforeach; ?>

                        <td class="av-notas-promedio <?= $promedioNotas === null
                            ? 'nota-sin-registro'
                            : (round($promedioNotas, 1) < 4.0
                                ? 'nota-reprobada'
                                : 'nota-aprobada') ?>">

                            <?= $promedioNotas !== null
                                ? number_format($promedioNotas, 1, ',', '.')
                                : '—' ?>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

<?php endif; ?>

        </section>

    <?php endif; ?>

</section>
        <?php endif; ?>

</main>

    <!-- FOOTER ORIGINAL DE AULAIA -->

    <footer class="av-footer">

        <div class="av-footer-central">

            <img src="img/Logo.png"
                 alt=""
                 class="av-footer-main-logo">

            <h2>AulaIA</h2>

            <p class="av-footer-description">
                Tu espacio para aprender, crecer y descubrir.
            </p>

        </div>

        <div class="av-footer-bottom">

            <div class="av-footer-project">
                <img src="img/Logo.png" alt="">
                <div>
                    <strong>AulaIA</strong>
                    <span>Plataforma educativa</span>
                </div>
            </div>

            <div class="av-footer-copyright">
                <span>&copy; <?= date('Y') ?> AulaIA.</span>
                <span>Todos los derechos reservados.</span>
            </div>

        </div>

    </footer>

</body>
</html>
