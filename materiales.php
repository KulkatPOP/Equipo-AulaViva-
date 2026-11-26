<?php

require_once __DIR__ . '/config/materiales.php';

$esProfesor = $rolMateriales === 'profesor';
$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET' && $metodo !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Método no permitido.');
}
if ($metodo === 'POST') {
    exigirRol('profesor');
}

$aulaId = filter_input($metodo === 'POST' ? INPUT_POST : INPUT_GET, 'aula_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);
if (!$aulaId) {
    http_response_code(400);
    exit('Aula inválida.');
}

$volver = $esProfesor
    ? 'aula.php?id=' . $aulaId
    : 'asignatura.php?aula_id=' . $aulaId;
$errorCarga = false;
$errorSubida = '';
try {
    require_once __DIR__ . '/config/conexion.php';
    $parametros = parametrosAulasMateriales() + [':aula_id' => $aulaId];
    $consultaAula = consultaAulasMateriales($esProfesor) . ' AND a.id = :aula_id';
    $stmt = $pdo->prepare($consultaAula);
    $stmt->execute($parametros);
    $aula = $stmt->fetch();
    if (!$aula) {
        http_response_code(403);
        exit('No tienes permiso para acceder a los materiales de esta aula.');
    }

    if ($metodo === 'POST') {
        $token = $_POST['csrf_token'] ?? null;
        if (!is_string($token) || !isset($_SESSION['csrf_subir_material'])
            || !hash_equals($_SESSION['csrf_subir_material'], $token)) {
            http_response_code(403);
            exit('El formulario no es válido. Vuelve a abrir el aula.');
        }


        /* ELIMINAR MATERIAL */

        $accion = $_POST['accion'] ?? 'subir';

        if ($accion === 'eliminar') {

            $materialId = filter_input(
                INPUT_POST,
                'material_id',
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$materialId) {
                http_response_code(400);
                exit('Material inválido.');
            }

            $consultaEliminar = consultaAulasMateriales(true)
                . ' AND a.id = :aula_id FOR SHARE OF a, u, vinculo';

            $stmt = $pdo->prepare(
                "WITH aula_autorizada AS ($consultaEliminar)
                DELETE FROM materiales AS m
                USING aula_autorizada AS autorizada
                WHERE m.id = :material_id
                AND m.aula_id = autorizada.id"
            );

            $stmt->execute($parametros + [
                ':material_id' => $materialId
            ]);

            if ($stmt->rowCount() !== 1) {
                http_response_code(404);
                exit('El material no existe o no tienes permiso para eliminarlo.');
            }

            $_SESSION['material_eliminado_aula'] = $aulaId;

            header(
                'Location: materiales.php?aula_id=' . $aulaId,
                true,
                303
            );

            exit;
        }

        if ($accion !== 'subir') {
            http_response_code(400);
            exit('Acción inválida.');
        }

        $titulo = trim(filter_input(INPUT_POST, 'titulo') ?: '');
        $archivo = $_FILES['archivo'] ?? [];
        $errorSubida = validarSubidaMaterial($titulo, $archivo);
        if ($errorSubida === '') {
            $contenido = file_get_contents($archivo['tmp_name']);
            if ($contenido === false) {
                throw new RuntimeException('No se pudo leer el archivo cargado.');
            }
            if (!str_starts_with($contenido, '%PDF-') || !str_ends_with(rtrim($contenido), '%%EOF')
                || (new finfo(FILEINFO_MIME_TYPE))->buffer($contenido) !== 'application/pdf') {
                $errorSubida = 'El archivo debe ser un PDF válido.';
            } else {
                $nombre = preg_replace('/[^A-Za-z0-9._-]+/', '_', $archivo['name']);
                $nombre = trim(substr(pathinfo($nombre, PATHINFO_FILENAME), 0, 170), '._-');
                $nombre = ($nombre !== '' ? $nombre : 'material') . '.pdf';

                // Los permisos se verifican nuevamente en la sentencia que guarda el PDF.
                $consultaSubida = consultaAulasMateriales(true) . ' AND a.id = :aula_id FOR SHARE OF a, u, vinculo';
                $stmt = $pdo->prepare(
    "WITH aula_autorizada AS ($consultaSubida),
    material_nuevo AS (
        INSERT INTO materiales (
            aula_id,
            titulo,
            nombre_archivo,
            contenido
        )
        SELECT
            id,
            :titulo,
            :nombre,
            decode(:contenido, 'base64')
        FROM aula_autorizada
        RETURNING id
    )
    INSERT INTO material_ingestas (
        material_id,
        estado,
        intentos
    )
    SELECT id, 'pendiente', 0
    FROM material_nuevo"
);
                $stmt->execute($parametros + [
                    ':titulo' => $titulo,
                    ':nombre' => $nombre,
                    ':contenido' => base64_encode($contenido),
                ]);
                if ($stmt->rowCount() !== 1) {
                    http_response_code(403);
                    exit('No tienes permiso para subir materiales a esta aula.');
                }
                $_SESSION['material_subido_aula'] = $aulaId;
                header('Location: materiales.php?aula_id=' . $aulaId, true, 303);
                exit;
            }
        }
        http_response_code(422);
    }

    $stmt = $pdo->prepare(
        "SELECT m.id, m.titulo FROM materiales AS m
         JOIN ($consultaAula) AS autorizada ON autorizada.id = m.aula_id
         ORDER BY m.id DESC"
    );
    $stmt->execute($parametros);
    $materiales = $stmt->fetchAll();
} catch (RuntimeException $e) {
    http_response_code(500);
    $errorCarga = true;
}

function validarSubidaMaterial(string $titulo, array $archivo): string
{
    if ($titulo === '' || preg_match('/\A.{1,120}\z/us', $titulo) !== 1) {
        return 'Ingresa un título de hasta 120 caracteres.';
    }
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_string($archivo['tmp_name'] ?? null) || !is_string($archivo['name'] ?? null)
        || !is_uploaded_file($archivo['tmp_name'])
        || $archivo['size'] < 1 || $archivo['size'] > 10 * 1024 * 1024) {
        return 'Selecciona un archivo PDF de hasta 10 MiB.';
    }
    return '';
}

$materialSubido = ($_SESSION['material_subido_aula'] ?? null) === $aulaId;
if ($materialSubido) {
    unset($_SESSION['material_subido_aula']);
}

$materialEliminado =
    ($_SESSION['material_eliminado_aula'] ?? null) === $aulaId;

if ($materialEliminado) {
    unset($_SESSION['material_eliminado_aula']);
}

if ($esProfesor) {
    $_SESSION['csrf_subir_material'] ??= bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Materiales | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/docente.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
    <script src="js/materiales.js" defer></script>
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

            <a href="<?= $esProfesor ? 'mis_aulas.php' : 'mis_cursos.php' ?>"
               class="av-logo">

                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>

            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">

                <strong>
                    <?= escaparMaterial($_SESSION['nombre'] ?? '') ?>
                </strong>

                <span><?= $esProfesor ? 'Profesor' : 'Estudiante' ?></span>

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

        <nav class="av-sidebar-nav" aria-label="Menú principal">

            <span class="av-menu-label">GENERAL</span>

            <a href="<?= $esProfesor ? 'mis_aulas.php' : 'mis_cursos.php' ?>">
                Inicio
            </a>

            <span class="av-menu-label">AULA ACTUAL</span>

            <a href="<?= escaparMaterial($volver) ?>">
                <?= escaparMaterial($aula['nombre'] ?? 'Mis cursos') ?>
            </a>

            <a href="materiales.php?aula_id=<?= $aulaId ?>"
               class="av-menu-active"
               aria-current="page">

                Materiales

            </a>

            <?php if ($esProfesor): ?>

                <a href="evaluaciones.php?aula_id=<?= $aulaId ?>">
                    Evaluaciones
                </a>

                <a href="notas.php?aula_id=<?= $aulaId ?>">
                    Notas
                </a>

                <a href="aula.php?id=<?= $aulaId ?>#alumnos-aula">
                    Estudiantes
                </a>

            <?php endif; ?>

        </nav>

        <div class="av-sidebar-bottom">

            <a href="logout.php">Cerrar sesión</a>

        </div>

    </dialog>

    <main class="av-main">

        <!-- NAVEGACIÓN -->

        <div class="av-breadcrumb">

            <a href="<?= $esProfesor ? 'mis_aulas.php' : 'mis_cursos.php' ?>">
                Inicio
            </a>

            <span>/</span>

            <a href="<?= escaparMaterial($volver) ?>">
                <?= escaparMaterial($aula['nombre'] ?? 'Aula') ?>
            </a>

            <span>/</span>
            <span>Materiales</span>

        </div>

        <!-- BANNER -->

        <section class="av-welcome av-class-banner">

            <div class="av-welcome-text">

                <span class="av-eyebrow">
                    RECURSOS EDUCATIVOS
                </span>

                <h1>
    <?= $esProfesor
        ? 'Materiales del aula'
        : 'Materiales de ' . escaparMaterial(
            $aula['asignatura_nombre'] ?: $aula['nombre']
        ) ?>
</h1>

                <p>
                    <?= $esProfesor
                        ? 'Comparte y administra los recursos de aprendizaje de tus estudiantes.'
                        : 'Consulta y descarga los recursos de aprendizaje de tu aula.' ?>
                </p>

                <a href="<?= escaparMaterial($volver) ?>"
                   class="av-welcome-button">

                    <?= $esProfesor ? 'Volver al aula' : 'Volver a la asignatura' ?>

                </a>

            </div>

        </section>

        <?php if ($errorCarga): ?>

            <p class="av-notes-message av-notes-error" role="alert">
                No se pudo completar la solicitud de materiales.
                Inténtalo nuevamente.
            </p>

        <?php else: ?>

            <?php if ($materialSubido): ?>

                <p class="av-notes-message av-notes-success" role="status">
                    Material subido correctamente.
                </p>

            <?php endif; ?>

            <?php if ($materialEliminado): ?>

                <p class="av-notes-message av-notes-success" role="status">
                    Material eliminado correctamente.
                </p>

            <?php endif; ?>

            <!-- SUBIR MATERIAL -->

            <?php if ($esProfesor): ?>

                <section class="av-courses"
                         aria-labelledby="titulo-subir">

                    <div class="av-section-heading">
                        <h2 id="titulo-subir">Subir material</h2>
                    </div>

                    <form class="av-material-upload"
                          method="post"
                          enctype="multipart/form-data">

                        <input type="hidden"
                               name="accion"
                               value="subir">

                        <input type="hidden"
                               name="aula_id"
                               value="<?= $aulaId ?>">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escaparMaterial($_SESSION['csrf_subir_material']) ?>">

                        <?php if ($errorSubida !== ''): ?>

                            <p class="av-notes-message av-notes-error"
                               role="alert">

                                <?= escaparMaterial($errorSubida) ?>

                            </p>

                        <?php endif; ?>

                        <div class="av-material-field">

                            <label for="titulo-material">
                                Título del material
                            </label>

                            <input id="titulo-material"
                                   name="titulo"
                                   maxlength="120"
                                   placeholder="Ej. Guía de Ciencias Naturales"
                                   value="<?= escaparMaterial($titulo ?? '') ?>"
                                   required>

                        </div>

                        <div class="av-material-field">

                            <label for="archivo-material">
                                Archivo PDF
                            </label>

                            <div class="av-material-file">

                                <svg viewBox="0 0 24 24"
                                     width="32" height="32"
                                     fill="none"
                                     stroke="currentColor"
                                     stroke-width="1.5"
                                     stroke-linecap="round"
                                     stroke-linejoin="round"
                                     aria-hidden="true">

                                    <path d="M12 16V3M7 8l5-5 5 5"/>
                                    <path d="M4 16v4h16v-4"/>

                                </svg>

                                <strong>Selecciona un archivo PDF</strong>

                                <span>Tamaño máximo: 10 MiB</span>

                                <input id="archivo-material"
                                       name="archivo"
                                       type="file"
                                       accept="application/pdf,.pdf"
                                       required>

                            </div>

                        </div>

                        <div class="av-material-upload-actions">

                            <button type="submit"
                                    class="av-notes-button">

                                Subir material

                            </button>

                        </div>

                    </form>

                </section>

            <?php endif; ?>

            <!-- MATERIALES DISPONIBLES -->

            <section class="av-courses"
                     aria-labelledby="titulo-materiales">

                <div class="av-section-heading">

                    <h2 id="titulo-materiales">
                        Materiales disponibles
                    </h2>

                    <span class="av-material-count">
                        <?= count($materiales) ?>
                        <?= count($materiales) === 1
                            ? 'material'
                            : 'materiales' ?>
                    </span>

                </div>

                <?php if (!$materiales): ?>

                    <div class="av-empty">
                        No hay materiales disponibles para esta aula.
                    </div>

                <?php else: ?>

                    <div class="av-material-grid">

                        <?php foreach ($materiales as $material): ?>

                            <article class="av-material-card">

                                <div class="av-material-card-top">

                                    <div class="av-material-icon">

                                        <svg viewBox="0 0 24 24"
                                             width="26" height="26"
                                             fill="none"
                                             stroke="currentColor"
                                             stroke-width="1.7"
                                             stroke-linecap="round"
                                             stroke-linejoin="round"
                                             aria-hidden="true">

                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                            <path d="M14 2v6h6M8 13h8M8 17h8"/>

                                        </svg>

                                    </div>

                                    <?php if ($esProfesor): ?>

                                        <button type="button"
                                                class="av-material-delete"
                                                data-material="<?= (int) $material['id'] ?>"
                                                data-titulo="<?= escaparMaterial($material['titulo']) ?>"
                                                aria-label="Eliminar <?= escaparMaterial($material['titulo']) ?>"
                                                title="Eliminar material">

                                            <svg viewBox="0 0 24 24"
                                                 width="19" height="19"
                                                 fill="none"
                                                 stroke="currentColor"
                                                 stroke-width="1.7"
                                                 stroke-linecap="round"
                                                 stroke-linejoin="round"
                                                 aria-hidden="true">

                                                <path d="M3 6h18M8 6V4h8v2"/>
                                                <path d="M5 6l1 14h12l1-14"/>
                                                <path d="M10 10v6M14 10v6"/>

                                            </svg>

                                        </button>

                                    <?php endif; ?>

                                </div>

                                <h3>
                                    <?= escaparMaterial($material['titulo']) ?>
                                </h3>

                                <p>Documento PDF</p>

                                <div class="av-material-card-actions">

                                    <a class="av-material-open"
                                       href="archivo_material.php?material_id=<?= (int) $material['id'] ?>"
                                       aria-label="Abrir <?= escaparMaterial($material['titulo']) ?>">

                                        Abrir

                                    </a>

                                    <a class="av-material-download"
                                       href="archivo_material.php?material_id=<?= (int) $material['id'] ?>&amp;descargar=1"
                                       aria-label="Descargar <?= escaparMaterial($material['titulo']) ?>"
                                       title="Descargar material">

                                        <svg viewBox="0 0 24 24"
                                             width="20" height="20"
                                             fill="none"
                                             stroke="currentColor"
                                             stroke-width="1.7"
                                             stroke-linecap="round"
                                             stroke-linejoin="round"
                                             aria-hidden="true">

                                            <path d="M12 3v12M7 10l5 5 5-5"/>
                                            <path d="M4 17v4h16v-4"/>

                                        </svg>

                                        Descargar

                                    </a>

                                </div>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        <?php endif; ?>

    </main>

    <!-- CONFIRMAR ELIMINACIÓN -->

    <?php if ($esProfesor && !$errorCarga): ?>

        <dialog id="modal-eliminar-material"
                class="av-material-modal"
                aria-labelledby="titulo-eliminar-material">

            <div class="av-material-modal-icon">

                <svg viewBox="0 0 24 24"
                     width="25" height="25"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.7"
                     stroke-linecap="round"
                     stroke-linejoin="round"
                     aria-hidden="true">

                    <path d="M3 6h18M8 6V4h8v2"/>
                    <path d="M5 6l1 14h12l1-14"/>
                    <path d="M10 10v6M14 10v6"/>

                </svg>

            </div>

            <h2 id="titulo-eliminar-material">
                ¿Eliminar material?
            </h2>

            <p>
                Se eliminará permanentemente
                <strong id="nombre-material-eliminar"></strong>.
            </p>

            <p>
                El documento dejará de estar disponible para los
                estudiantes y el tutor de inteligencia artificial.
            </p>

            <form method="post" id="form-eliminar-material">

                <input type="hidden"
                       name="accion"
                       value="eliminar">

                <input type="hidden"
                       name="aula_id"
                       value="<?= $aulaId ?>">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= escaparMaterial($_SESSION['csrf_subir_material']) ?>">

                <input type="hidden"
                       name="material_id"
                       id="material-eliminar">

                <div class="av-material-modal-actions">

                    <button type="button"
                            id="cancelar-eliminar-material"
                            class="av-material-cancel"
                            autofocus>

                        Cancelar

                    </button>

                    <button type="submit"
                            class="av-material-confirm">

                        Eliminar material

                    </button>

                </div>

            </form>

        </dialog>

    <?php endif; ?>

    <?php if ($esProfesor): ?>
        <?php require __DIR__ . '/includes/footer.php'; ?>
    <?php endif; ?>

</body>

</html>