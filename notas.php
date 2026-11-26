<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/evaluaciones.php';
require_once __DIR__ . '/config/notas.php';

exigirRol('profesor');

header('Cache-Control: private, no-store');

if (!isset($pdo) || !($pdo instanceof PDO)) {
    require_once __DIR__ . '/config/conexion.php';
}

function escaparNota(string $valor): string
{
    return htmlspecialchars(
        $valor,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function validarIdNota(mixed $valor): int|false
{
    return filter_var(
        $valor,
        FILTER_VALIDATE_INT,
        ['options' => [
            'min_range' => 1,
            'max_range' => 2147483647
        ]]
    );
}

/* VALIDAR ACCESO */

$aulaId = validarIdNota($_GET['aula_id'] ?? null);

if (!$aulaId) {
    http_response_code(400);
    exit('Aula inválida.');
}

$profesorId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$_SESSION['csrf_notas'] ??= bin2hex(random_bytes(32));

$urlNotas = 'notas.php?aula_id=' . $aulaId;

try {

    $aula = aulaEvaluaciones($pdo, $aulaId);

    if (!$aula) {
        http_response_code(403);
        exit('No tienes permiso para acceder a esta aula.');
    }

    /* PROCESAR ACCIONES */

    if ($metodo === 'POST') {

        $token = $_POST['csrf_token'] ?? null;

        if (
            !is_string($token)
            || !hash_equals($_SESSION['csrf_notas'], $token)
        ) {
            http_response_code(403);
            exit('El formulario ha caducado.');
        }

        $accion = $_POST['accion'] ?? '';

        try {

            $pdo->beginTransaction();

            if (!aulaEvaluaciones($pdo, $aulaId, true)) {
                $pdo->rollBack();
                http_response_code(403);
                exit('No tienes acceso a esta aula.');
            }

            bloquearLibroNotas($pdo, $aulaId);

            switch ($accion) {

                case 'inicializar':

                    inicializarLibroNotas(
                        $pdo,
                        $aulaId,
                        $profesorId
                    );

                    $mensaje = 'Libro de notas actualizado correctamente.';

                    break;

                case 'agregar':

                    inicializarLibroNotas(
                        $pdo,
                        $aulaId,
                        $profesorId
                    );

                    crearColumnaNota(
                        $pdo,
                        $aulaId,
                        $profesorId
                    );

                    $mensaje = 'Nueva nota agregada correctamente.';

                    break;

                case 'eliminar':

                    $columnaId = validarIdNota(
                        $_POST['columna_id'] ?? null
                    );

                    if (!$columnaId) {
                        throw new InvalidArgumentException(
                            'Columna de nota inválida.'
                        );
                    }

                    eliminarColumnaNota(
                        $pdo,
                        $aulaId,
                        $columnaId
                    );

                    $mensaje = 'Nota eliminada correctamente.';

                    break;

                case 'guardar':

                    $entrada = $_POST['notas'] ?? '';

                    if (
                        !is_string($entrada)
                        || strlen($entrada) > 1048576
                    ) {
                        throw new InvalidArgumentException(
                            'Solicitud de notas inválida.'
                        );
                    }

                    $calificaciones = json_decode(
                        $entrada,
                        true,
                        16,
                        JSON_THROW_ON_ERROR
                    );

                    if (
                        !is_array($calificaciones)
                        || !array_is_list($calificaciones)
                        || count($calificaciones) > 10000
                    ) {
                        throw new InvalidArgumentException(
                            'Formato de calificaciones inválido.'
                        );
                    }

                    foreach ($calificaciones as $fila) {

                        if (!is_array($fila)) {
                            throw new InvalidArgumentException(
                                'Calificación inválida.'
                            );
                        }

                        $columnaId = validarIdNota(
                            $fila['columna'] ?? null
                        );

                        $estudianteId = validarIdNota(
                            $fila['estudiante'] ?? null
                        );

                        if (!$columnaId || !$estudianteId) {
                            throw new InvalidArgumentException(
                                'Identificador de nota inválido.'
                            );
                        }

                        guardarCalificacionLibro(
                            $pdo,
                            $aulaId,
                            $colegioId,
                            $profesorId,
                            $columnaId,
                            $estudianteId,
                            $fila['valor'] ?? null
                        );
                    }

                    $mensaje = count($calificaciones) > 0
                        ? 'Calificaciones guardadas correctamente.'
                        : 'No hay cambios para guardar.';

                    break;

                default:

                    throw new InvalidArgumentException(
                        'Acción no válida.'
                    );
            }

            $pdo->commit();

            $_SESSION['mensaje_notas'] = [
                'tipo' => 'success',
                'texto' => $mensaje
            ];

        } catch (InvalidArgumentException | JsonException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $_SESSION['mensaje_notas'] = [
                'tipo' => 'error',
                'texto' => $e instanceof JsonException
                    ? 'Formato de notas inválido.'
                    : $e->getMessage()
            ];
        }

        header('Location: ' . $urlNotas, true, 303);
        exit;
    }

    /* ESTUDIANTES */

    $stmt = $pdo->prepare(
        "SELECT u.id, u.nombre, u.apellido
         FROM usuarios AS u
         INNER JOIN aula_alumnos AS aa
             ON aa.alumno_id = u.id
         WHERE aa.aula_id = ?
           AND u.colegio_id = ?
           AND u.rol = 'alumno'
           AND u.activo = TRUE
         ORDER BY u.apellido ASC, u.nombre ASC"
    );

    $stmt->execute([$aulaId, $colegioId]);

    $alumnos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* LIBRO DE NOTAS */

    $columnas = obtenerColumnasNotas($pdo, $aulaId);

    $notas = obtenerCalificacionesLibro(
        $pdo,
        $aulaId,
        $colegioId
    );

    $stmt = $pdo->prepare(
        'SELECT DISTINCT libro_nota_id
         FROM notas_manuales
         WHERE aula_id = ?'
    );

    $stmt->execute([$aulaId]);

    $columnasOcupadas = array_fill_keys(
        $stmt->fetchAll(PDO::FETCH_COLUMN),
        true
    );

    /* EVALUACIONES PUBLICADAS */

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM evaluaciones
         WHERE aula_id = ?
           AND publicada_en IS NOT NULL
           AND publicada_en <= CURRENT_TIMESTAMP'
    );

    $stmt->execute([$aulaId]);

    $totalEvaluaciones = (int) $stmt->fetchColumn();

    $totalAutomaticas = count(array_filter(
        $columnas,
        static fn($columna) =>
            $columna['tipo'] === 'automatica'
    ));

    $requiereSincronizacion =
        count($columnas) < 3
        || $totalEvaluaciones > $totalAutomaticas;

    /* PROMEDIOS */

    $promedios = [];

    foreach ($alumnos as $alumno) {

        $estudianteId = (int) $alumno['id'];

        $promedios[$estudianteId] = calcularPromedioNotas(
            $notas[$estudianteId] ?? []
        );
    }

    $promedioGeneral = calcularPromedioNotas(
        array_filter(
            $promedios,
            static fn($promedio) => $promedio !== null
        )
    );

    $totalAlumnos = count($alumnos);

    $mensaje = $_SESSION['mensaje_notas'] ?? null;

    unset($_SESSION['mensaje_notas']);

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Libro de notas: ' . $e->getMessage());

    http_response_code(500);

    exit('No se pudieron procesar las calificaciones.');
}

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Notas | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/docente.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
    <script src="js/notas.js" defer></script>

</head>

<body class="av-dashboard">

    <!-- HEADER -->

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
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     aria-hidden="true">

                    <path d="M3 6h18M3 12h18M3 18h18"/>

                </svg>

            </button>

            <a href="mis_aulas.php" class="av-logo">

                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>

            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">

                <strong>
                    <?= escaparNota($_SESSION['nombre'] ?? '') ?>
                </strong>

                <span>Profesor</span>

            </div>

            <div class="av-avatar" aria-hidden="true">

                <svg viewBox="0 0 24 24"
                     width="23" height="23"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.7">

                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>

                </svg>

            </div>

        </div>

    </header>

    <!-- MENÚ LATERAL -->

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

        <nav class="av-sidebar-nav"
             aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="mis_aulas.php">Inicio</a>

            <span class="av-menu-label">AULA ACTUAL</span>

            <a href="aula.php?id=<?= $aulaId ?>">
                <?= escaparNota($aula['nombre']) ?>
            </a>

            <a href="materiales.php?aula_id=<?= $aulaId ?>">
                Materiales
            </a>

            <a href="evaluaciones.php?aula_id=<?= $aulaId ?>">
                Evaluaciones
            </a>

            <a href="<?= escaparNota($urlNotas) ?>"
               class="av-menu-active"
               aria-current="page">

                Notas

            </a>

            <a href="aula.php?id=<?= $aulaId ?>#alumnos-aula">
                Estudiantes
            </a>

        </nav>

        <div class="av-sidebar-bottom">

            <a href="logout.php">Cerrar sesión</a>

        </div>

    </dialog>

    <main class="av-main">

        <div class="av-breadcrumb">

            <a href="mis_aulas.php">Inicio</a>

            <span>/</span>

            <a href="aula.php?id=<?= $aulaId ?>">
                <?= escaparNota($aula['nombre']) ?>
            </a>

            <span>/</span>

            <span>Notas</span>

        </div>

        <!-- BANNER -->

        <section class="av-welcome av-class-banner">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    RENDIMIENTO ACADÉMICO
                </span>

                <h1>Notas del aula</h1>

                <p>
                    Consulta y administra las calificaciones
                    de los estudiantes de
                    <?= escaparNota($aula['nombre']) ?>.
                </p>

                <a href="aula.php?id=<?= $aulaId ?>"
                   class="av-welcome-button">

                    Volver al aula

                </a>

            </div>

        </section>

        <!-- RESUMEN ACADÉMICO -->

        <section class="av-courses"
                 aria-labelledby="resumen-academico">

            <div class="av-section-heading">

                <h2 id="resumen-academico">
                    Resumen del curso
                </h2>

            </div>

            <div class="av-notes-summary">

                <article class="av-notes-stat">

                    <span>Estudiantes inscritos</span>
                    <strong><?= $totalAlumnos ?></strong>

                </article>

                <article class="av-notes-stat">

                    <span>Evaluaciones publicadas</span>
                    <strong><?= $totalEvaluaciones ?></strong>

                </article>

                <article class="av-notes-stat">

                    <span>Promedio general</span>

                    <strong class="<?=
                        $promedioGeneral !== null
                        && round($promedioGeneral, 1) < 4.0
                            ? 'av-note-reprobada'
                            : ''
                    ?>">
                        <?= formatearNota($promedioGeneral) ?>
                    </strong>

                </article>

            </div>

        </section>

        <!-- LIBRO DE NOTAS -->

        <section class="av-courses"
                 aria-labelledby="registro-notas">

            <div class="av-section-heading">

                <h2 id="registro-notas">Notas</h2>

            </div>

            <?php if ($mensaje): ?>

                <div class="av-notes-message <?=
                    $mensaje['tipo'] === 'success'
                        ? 'av-notes-success'
                        : 'av-notes-error'
                ?>" role="status">

                    <?= escaparNota($mensaje['texto']) ?>

                </div>

            <?php endif; ?>

            <div class="av-notes-panel">

                <?php if ($requiereSincronizacion): ?>

                    <form method="POST"
                          action="<?= escaparNota($urlNotas) ?>"
                          class="av-notes-sync">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escaparNota($_SESSION['csrf_notas']) ?>">

                        <input type="hidden"
                               name="accion"
                               value="inicializar">

                        <p>El libro de notas necesita actualizarse.</p>

                        <button type="submit"
                                class="av-notes-button">

                            Actualizar libro

                        </button>

                    </form>

                <?php endif; ?>

                <!-- ELIMINAR NOTA -->

                <form method="POST"
                      id="form-eliminar-nota"
                      action="<?= escaparNota($urlNotas) ?>">

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= escaparNota($_SESSION['csrf_notas']) ?>">

                    <input type="hidden"
                           name="accion"
                           value="eliminar">

                    <input type="hidden"
                           name="columna_id"
                           id="columna-eliminar">

                </form>

                <!-- HERRAMIENTAS -->

                <div class="av-notes-toolbar av-notes-toolbar-actions">

                    <input type="search"
                           id="buscar-estudiante"
                           class="av-notes-search"
                           placeholder="Buscar estudiante..."
                           aria-label="Buscar estudiante"
                           autocomplete="off">

                    <form method="POST"
                          id="form-agregar-nota"
                          action="<?= escaparNota($urlNotas) ?>">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escaparNota($_SESSION['csrf_notas']) ?>">

                        <input type="hidden"
                               name="accion"
                               value="agregar">

                        <button type="submit"
                                class="av-notes-button">

                            + Agregar nota

                        </button>

                    </form>

                </div>

                <?php if (!$columnas): ?>

                    <div class="av-empty">

                        Actualiza el libro para comenzar a registrar notas.

                    </div>

                <?php else: ?>

                    <form method="POST"
                          id="form-guardar-notas"
                          action="<?= escaparNota($urlNotas) ?>">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escaparNota($_SESSION['csrf_notas']) ?>">

                        <input type="hidden"
                               name="accion"
                               value="guardar">

                        <input type="hidden"
                               name="notas"
                               id="notas-json"
                               value="[]">

                        <div class="av-notes-table-wrapper">

                            <table class="av-notes-table"
                                   id="tabla-notas">

                                <thead>

                                    <tr>

                                        <th scope="col">
                                            Estudiante
                                        </th>

                                        <?php foreach ($columnas as $indice => $columna): ?>

                                            <th scope="col"
                                                title="<?= escaparNota(
                                                    $columna['evaluacion_titulo']
                                                    ?? 'Calificación manual'
                                                ) ?>">

                                                Nota <?= $indice + 1 ?>

                                                <small class="av-note-type">

                                                    <?= $columna['tipo'] === 'automatica'
                                                        ? 'Automática'
                                                        : 'Manual' ?>

                                                </small>

                                                <?php if (
                                                    $columna['tipo'] === 'manual'
                                                    && count($columnas) > 3
                                                    && !isset($columnasOcupadas[$columna['id']])
                                                ): ?>

                                                    <button type="button"
                                                            class="av-note-delete"
                                                            data-columna="<?= (int) $columna['id'] ?>"
                                                            data-titulo="Nota <?= $indice + 1 ?>"
                                                            aria-label="Eliminar Nota <?= $indice + 1 ?>"
                                                            title="Eliminar nota">

                                                        <svg viewBox="0 0 24 24"
                                                             width="16"
                                                             height="16"
                                                             fill="none"
                                                             stroke="currentColor"
                                                             stroke-width="1.7"
                                                             stroke-linecap="round"
                                                             stroke-linejoin="round"
                                                             aria-hidden="true">

                                                            <path d="M3 6h18"/>
                                                            <path d="M8 6V4h8v2"/>
                                                            <path d="M5 6l1 14h12l1-14"/>
                                                            <path d="M10 10v6M14 10v6"/>

                                                        </svg>

                                                    </button>

                                                <?php endif; ?>

                                            </th>

                                        <?php endforeach; ?>

                                        <th scope="col">
                                            Promedio
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php if (!$alumnos): ?>

                                        <tr>

                                            <td colspan="<?= count($columnas) + 2 ?>"
                                                class="av-empty">

                                                No hay estudiantes inscritos en esta aula.

                                            </td>

                                        </tr>

                                    <?php endif; ?>

                                    <?php foreach ($alumnos as $alumno): ?>

                                        <?php

                                        $estudianteId = (int) $alumno['id'];

                                        $nombreCompleto =
                                            $alumno['nombre'] . ' ' .
                                            $alumno['apellido'];

                                        $promedio = $promedios[$estudianteId];

                                        ?>

                                        <th scope="row" class="av-notes-student">
                                            <strong><?= escaparNota($nombreCompleto) ?></strong>
                                        </th>

                                            <?php foreach ($columnas as $indice => $columna): ?>

                                                <?php

                                                $columnaId = (int) $columna['id'];

                                                $nota = $notas[$estudianteId][$columnaId]
                                                    ?? null;

                                                $reprobada =
                                                    $nota !== null && $nota < 4.0;

                                                $bloqueada =
                                                    $columna['tipo'] === 'automatica'
                                                    && $nota === null;

                                                ?>

                                                <td>

                                                    <input type="text"
                                                           class="av-note-input <?= $reprobada
                                                               ? 'av-note-reprobada'
                                                               : '' ?>"
                                                           inputmode="decimal"
                                                           autocomplete="off"
                                                           maxlength="3"
                                                           placeholder="—"
                                                           pattern="[1-6]([.,][0-9])?|7([.,]0)?"
                                                           data-estudiante="<?= $estudianteId ?>"
                                                           data-columna="<?= $columnaId ?>"
                                                           aria-label="<?= escaparNota(
                                                               'Nota ' . ($indice + 1) .
                                                               ' de ' . $nombreCompleto
                                                           ) ?>"
                                                           value="<?= $nota === null
                                                               ? ''
                                                               : formatearNota($nota) ?>"
                                                           <?= $bloqueada
                                                               ? 'readonly title="Evaluación pendiente de calificación"'
                                                               : '' ?>>

                                                </td>

                                            <?php endforeach; ?>

                                            <td class="av-notes-average <?=
                                                $promedio !== null
                                                && round($promedio, 1) < 4.0
                                                    ? 'av-note-reprobada'
                                                    : ''
                                            ?>">

                                                <?= formatearNota($promedio) ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                        <p id="notas-sin-resultados"
                           class="av-empty"
                           hidden>

                            No se encontraron estudiantes.

                        </p>

                        <?php if ($alumnos): ?>

                            <div class="av-notes-actions">

                                <button type="submit"
                                        id="guardar-notas"
                                        class="av-notes-button"
                                        disabled>

                                    Guardar cambios

                                </button>

                            </div>

                        <?php endif; ?>

                        <noscript>
                            Activa JavaScript para administrar las calificaciones.
                        </noscript>

                    </form>

                <?php endif; ?>

            </div>

        </section>

    </main>

    <?php require __DIR__ . '/includes/footer.php'; ?>

</body>

</html>