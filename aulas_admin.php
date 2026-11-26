<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';
require_once __DIR__ . '/config/aulas_admin.php';

exigirRol('admin');
header('Cache-Control: private, no-store');

$adminId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];
$nombre = $_SESSION['nombre'] ?? 'Administrador';

if (!isset($_SESSION['csrf_admin_aulas'])) {
    $_SESSION['csrf_admin_aulas'] = bin2hex(random_bytes(32));
}

$mensaje = $_SESSION['mensaje_admin_aulas'] ?? '';
unset($_SESSION['mensaje_admin_aulas']);

$error = '';
$errorOperacion = $_SESSION['error_admin_aulas'] ?? '';
unset($_SESSION['error_admin_aulas']);
$aulas = [];
$aula = null;
$integrantes = [];

$disponibles = [
    'profesor' => [],
    'alumno' => []
];

$vista = $_GET['vista'] ?? 'listado';

if (!in_array($vista, ['listado', 'crear', 'editar', 'gestionar'], true)) {
    http_response_code(400);
    exit('Vista no válida.');
}

$aulaId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

try {

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        require __DIR__ . '/config/conexion.php';
    }

    // Procesar modificaciones.

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $csrf = $_POST['csrf_token'] ?? null;

        if (
            !is_string($csrf) ||
            !hash_equals($_SESSION['csrf_admin_aulas'], $csrf)
        ) {
            http_response_code(403);
            exit('Solicitud no válida.');
        }

        $accion = $_POST['accion'] ?? '';

        $id = filter_var(
            $_POST['aula_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $pdo->beginTransaction();

        if (!autorizarGestionAulas($pdo, $adminId, $colegioId)) {
            $pdo->rollBack();
            http_response_code(403);
            exit('Acceso denegado.');
        }

        switch ($accion) {
case 'retirar_integrante':

    $usuarioId = filter_var(
        $_POST['usuario_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $rol = $_POST['rol'] ?? '';

    if (
        !$id ||
        !$usuarioId ||
        !in_array($rol, ['profesor', 'alumno'], true)
    ) {
        throw new InvalidArgumentException(
            'Datos del integrante no válidos.'
        );
    }

    try {

        retirarIntegranteAulaAdmin(
            $pdo,
            $colegioId,
            $id,
            $usuarioId,
            $rol
        );

        $pdo->commit();

        $_SESSION['mensaje_admin_aulas'] =
            $rol === 'profesor'
                ? 'Profesor retirado correctamente.'
                : 'Estudiante retirado correctamente.';

    } catch (InvalidArgumentException $e) {

        $pdo->rollBack();

        $_SESSION['error_admin_aulas'] = $e->getMessage();
    }

    header(
        'Location: aulas_admin.php?vista=gestionar&id=' . $id,
        true,
        303
    );

    exit;
        case 'agregar_integrante':

    $usuarioId = filter_var(
        $_POST['usuario_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $rol = $_POST['rol'] ?? '';

    if (
        !$id ||
        !$usuarioId ||
        !in_array($rol, ['profesor', 'alumno'], true)
    ) {
        throw new InvalidArgumentException(
            'Datos del integrante no válidos.'
        );
    }

    if (!agregarIntegranteAulaAdmin(
        $pdo,
        $colegioId,
        $id,
        $usuarioId,
        $rol
    )) {
        throw new InvalidArgumentException(
            'El usuario no está disponible o ya pertenece al aula.'
        );
    }

    $pdo->commit();

    $_SESSION['mensaje_admin_aulas'] =
        $rol === 'profesor'
            ? 'Profesor incorporado correctamente.'
            : 'Estudiante incorporado correctamente.';

    header(
        'Location: aulas_admin.php?vista=gestionar&id=' . $id,
        true,
        303
    );

    exit;
            case 'crear':

                $curso = validarNombreAula($_POST['nombre'] ?? null);
                $asignatura = validarNombreAula(
                    $_POST['asignatura'] ?? null
                );

                crearAulaAdmin(
                    $pdo,
                    $colegioId,
                    $curso,
                    $asignatura
                );

                $mensaje = 'Aula creada correctamente.';

                break;

            case 'editar':

                $curso = validarNombreAula($_POST['nombre'] ?? null);
                $asignatura = validarNombreAula(
                    $_POST['asignatura'] ?? null
                );

                if (!$id || !editarAulaAdmin(
                    $pdo,
                    $colegioId,
                    $id,
                    $curso,
                    $asignatura
                )) {
                    throw new InvalidArgumentException(
                        'Aula no disponible.'
                    );
                }

                $mensaje = 'Aula actualizada correctamente.';

                break;

            case 'activar':
            case 'desactivar':

                if (!$id || !cambiarEstadoAulaAdmin(
                    $pdo,
                    $colegioId,
                    $id,
                    $accion === 'activar'
                )) {
                    throw new InvalidArgumentException(
                        'Aula no disponible.'
                    );
                }

                $mensaje = $accion === 'activar'
                    ? 'Aula activada correctamente.'
                    : 'Aula desactivada correctamente.';

                break;

            default:

                throw new InvalidArgumentException(
                    'Operación no válida.'
                );
        }

        $pdo->commit();

        $_SESSION['mensaje_admin_aulas'] = $mensaje;

        header('Location: aulas_admin.php', true, 303);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }

    // Obtener información según la vista.

    if ($vista === 'listado') {

        $aulas = listarAulasAdmin($pdo, $colegioId);

    } elseif (in_array($vista, ['editar', 'gestionar'], true)) {

        $aula = $aulaId
            ? obtenerAulaAdmin($pdo, $colegioId, $aulaId)
            : null;

        if ($aula === null) {
            http_response_code(404);
            exit('Aula no disponible.');
        }

        if ($vista === 'gestionar') {

            $integrantes = integrantesAulaAdmin(
                $pdo,
                $colegioId,
                $aulaId
            );
        }
        foreach (['profesor', 'alumno'] as $tipo) {

    $disponibles[$tipo] = usuariosDisponiblesAulaAdmin(
        $pdo,
        $colegioId,
        $aulaId,
        $tipo
    );
}
    }

} catch (InvalidArgumentException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    $error = $e->getMessage();

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Gestión de aulas: ' . $e->getMessage());

    http_response_code(500);
    $error = 'No se pudo completar la operación.';
}

$titulo = match ($vista) {
    'crear' => 'Crear aula',
    'editar' => 'Editar aula',
    'gestionar' => $aula['asignatura_nombre'] ?? 'Gestionar aula',
    default => 'Gestión de aulas'
};

$descripcion = match ($vista) {
    'crear' => 'Registra un nuevo curso y asignatura.',
    'editar' => 'Actualiza la información del aula.',
    'gestionar' => $aula['nombre'] ?? '',
    default => 'Organiza los cursos y asignaturas de tu establecimiento.'
};

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Aulas | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/admin.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
    <script src="js/aulas-admin.js" defer></script>

</head>

<body class="av-dashboard">

    <!-- Encabezado -->

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

            <a href="panel_admin.php" class="av-logo">
                <img src="img/Logo.png" alt="">
                <span>AulaIA</span>
            </a>

        </div>

        <div class="av-profile">

            <div class="av-profile-text">
                <strong><?= escaparApoderados($nombre) ?></strong>
                <span>Administrador</span>
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

    <!-- Menú lateral -->

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

            <a href="panel_admin.php">Inicio</a>

            <span class="av-menu-label">ADMINISTRACIÓN</span>

            <a href="usuarios_admin.php">Usuarios</a>

            <a href="aulas_admin.php"
               class="av-menu-active"
               aria-current="page">
                Aulas
            </a>

            <a href="vinculos_apoderado.php">Vinculaciones</a>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- Contenido -->

    <main class="av-main">

        <div class="av-breadcrumb">

            <a href="panel_admin.php">Inicio</a>
            <span aria-hidden="true">/</span>

            <?php if ($vista === 'listado'): ?>

                <span>Aulas</span>

            <?php else: ?>

                <a href="aulas_admin.php">Aulas</a>
                <span aria-hidden="true">/</span>
                <span><?= escaparApoderados($titulo) ?></span>

            <?php endif; ?>

        </div>

        <section class="av-welcome">

            <div class="av-welcome-text">

                <span class="av-eyebrow">ADMINISTRACIÓN</span>

                <h1><?= escaparApoderados($titulo) ?></h1>

                <p><?= escaparApoderados($descripcion) ?></p>

            </div>

        </section>

        <section class="av-courses">
            <?php if ($errorOperacion !== ''): ?>

    <div class="admin-mensaje admin-error" role="alert">
        <?= escaparApoderados($errorOperacion) ?>
    </div>

<?php endif; ?>

            <?php if ($mensaje !== ''): ?>

                <div class="admin-mensaje" role="status">
                    <?= escaparApoderados($mensaje) ?>
                </div>

            <?php endif; ?>

            <?php if ($error !== ''): ?>

                <div class="admin-mensaje admin-error" role="alert">
                    <?= escaparApoderados($error) ?>
                </div>

            <?php elseif ($vista === 'listado'): ?>

                <!-- Listado de aulas -->

                <div class="admin-seccion-header">

                    <h2>Aulas registradas</h2>

                    <a class="admin-btn"
                       href="aulas_admin.php?vista=crear">
                        + Crear aula
                    </a>

                </div>

                <?php if (!$aulas): ?>

                    <div class="admin-panel">
                        Todavía no hay aulas registradas.
                    </div>

                <?php else: ?>

                    <div class="admin-grid">

                        <?php foreach ($aulas as $item): ?>

                            <?php
                            $activa = aulaAdminActiva($item['activo']);
                            ?>

                            <article class="admin-card admin-aula">

                                <div class="admin-aula-header">

                                    <div>

                                        <h3>
                                            <?= escaparApoderados(
                                                $item['asignatura_nombre']
                                                ?: $item['nombre']
                                            ) ?>
                                        </h3>

                                        <span class="admin-curso">
                                            <?= escaparApoderados($item['nombre']) ?>
                                        </span>

                                    </div>

                                    <span class="<?= $activa
                                        ? 'admin-activo'
                                        : 'admin-inactivo' ?>">

                                        <?= $activa ? 'Activa' : 'Inactiva' ?>

                                    </span>

                                </div>

                                <div class="admin-aula-datos">

                                    <div>
                                        <span>Profesores</span>
                                        <strong><?= (int) $item['profesores'] ?></strong>
                                    </div>

                                    <div>
                                        <span>Estudiantes</span>
                                        <strong><?= (int) $item['estudiantes'] ?></strong>
                                    </div>

                                </div>

                                <div class="admin-aula-acciones">

                                    <a class="admin-acceso"
                                       href="aulas_admin.php?vista=gestionar&id=<?= (int) $item['id'] ?>">
                                        Gestionar aula &rarr;
                                    </a>

                                    <a class="admin-editar"
                                       href="aulas_admin.php?vista=editar&id=<?= (int) $item['id'] ?>">
                                        Editar
                                    </a>

                                </div>

                                <form method="POST"
                                      class="admin-form-estado admin-aula-estado">

                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= escaparApoderados($_SESSION['csrf_admin_aulas']) ?>">

                                    <input type="hidden"
                                           name="aula_id"
                                           value="<?= (int) $item['id'] ?>">

                                    <input type="hidden"
                                           name="accion"
                                           value="<?= $activa ? 'desactivar' : 'activar' ?>">

                                    <button type="submit"
                                            class="admin-estado-btn <?= $activa
                                                ? 'admin-desactivar'
                                                : 'admin-activar' ?>">

                                        <?= $activa
                                            ? 'Desactivar aula'
                                            : 'Activar aula' ?>

                                    </button>

                                </form>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            <?php elseif (in_array($vista, ['crear', 'editar'], true)): ?>

                <!-- Formulario de aula -->

                <div class="admin-panel admin-form-panel">

                    <h2>Información del aula</h2>

                    <form method="POST" class="admin-form">

                        <input type="hidden"
                               name="csrf_token"
                               value="<?= escaparApoderados($_SESSION['csrf_admin_aulas']) ?>">

                        <input type="hidden"
                               name="accion"
                               value="<?= $vista === 'crear' ? 'crear' : 'editar' ?>">

                        <?php if ($vista === 'editar'): ?>

                            <input type="hidden"
                                   name="aula_id"
                                   value="<?= (int) $aula['id'] ?>">

                        <?php endif; ?>

                        <div class="admin-campo">

                            <label for="nombre">Curso</label>

                            <input type="text"
                                   id="nombre"
                                   name="nombre"
                                   placeholder="Ej. 8° Básico A"
                                   maxlength="120"
                                   value="<?= escaparApoderados($aula['nombre'] ?? '') ?>"
                                   required>

                        </div>

                        <div class="admin-campo">

                            <label for="asignatura">Asignatura</label>

                            <input type="text"
                                   id="asignatura"
                                   name="asignatura"
                                   placeholder="Ej. Matemáticas"
                                   maxlength="120"
                                   value="<?= escaparApoderados($aula['asignatura_nombre'] ?? '') ?>"
                                   required>

                        </div>

                        <div class="admin-form-acciones">

                            <button type="submit" class="admin-btn">

                                <?= $vista === 'crear'
                                    ? 'Crear aula'
                                    : 'Guardar cambios' ?>

                            </button>

                            <a class="admin-editar" href="aulas_admin.php">
                                Cancelar
                            </a>

                        </div>

                    </form>

                </div>

            <?php elseif ($vista === 'gestionar'): ?>

                <!-- Integrantes del aula -->

                <?php foreach ([
                    'profesor' => 'Profesores',
                    'alumno' => 'Estudiantes'
                ] as $rol => $etiqueta): ?>

                    <div class="admin-panel admin-integrantes">


<div class="admin-integrantes-header">

    <h2><?= escaparApoderados($etiqueta) ?></h2>

    <?php if (aulaAdminActiva($aula['activo'])): ?>

        <button type="button"
                class="admin-btn"
                data-abrir-integrantes="<?= escaparApoderados($rol) ?>">

            + Agregar <?= $rol === 'profesor'
                ? 'profesor'
                : 'estudiante' ?>

        </button>

    <?php endif; ?>

</div>

<dialog class="admin-dialog"
        id="agregar-<?= escaparApoderados($rol) ?>"
        aria-labelledby="titulo-<?= escaparApoderados($rol) ?>">

    <div class="admin-dialog-header">

        <h2 id="titulo-<?= escaparApoderados($rol) ?>">

            Agregar <?= $rol === 'profesor'
                ? 'profesor'
                : 'estudiante' ?>

        </h2>

        <button type="button"
                class="admin-dialog-cerrar"
                data-cerrar-integrantes
                aria-label="Cerrar">
            &times;
        </button>

    </div>

    <?php if (!$disponibles[$rol]): ?>

        <p class="admin-dialog-vacio">
            No hay usuarios disponibles para incorporar.
        </p>

        <button type="button"
                class="admin-btn"
                data-cerrar-integrantes>
            Cerrar
        </button>

    <?php else: ?>

        <input type="search"
               class="admin-buscar-integrante"
               placeholder="Buscar por nombre o usuario..."
               aria-label="Buscar integrante">

        <div class="admin-disponibles">

            <?php foreach ($disponibles[$rol] as $persona): ?>

                <form method="POST"
                      class="admin-persona"
                      data-persona>

                    <input type="hidden"
                           name="csrf_token"
                           value="<?= escaparApoderados($_SESSION['csrf_admin_aulas']) ?>">

                    <input type="hidden"
                           name="accion"
                           value="agregar_integrante">

                    <input type="hidden"
                           name="aula_id"
                           value="<?= (int) $aula['id'] ?>">

                    <input type="hidden"
                           name="usuario_id"
                           value="<?= (int) $persona['id'] ?>">

                    <input type="hidden"
                           name="rol"
                           value="<?= escaparApoderados($rol) ?>">

                    <div class="admin-persona-info">

                        <strong>
                            <?= escaparApoderados(
                                $persona['nombre'] . ' ' .
                                $persona['apellido']
                            ) ?>
                        </strong>

                        <span>
                            <?= escaparApoderados($persona['username']) ?>
                        </span>

                    </div>

                    <button type="submit" class="admin-btn">
                        Agregar
                    </button>

                </form>

            <?php endforeach; ?>

            <p class="admin-dialog-vacio"
               data-sin-resultados
               hidden>
                No se encontraron usuarios.
            </p>

        </div>

    <?php endif; ?>

</dialog>


                        <?php

                        $grupo = array_filter(
                            $integrantes,
                            static fn($u) => $u['rol'] === $rol
                        );

                        ?>

                        <?php if (!$grupo): ?>

                            <p class="admin-curso">
                                No hay integrantes registrados.
                            </p>

                        <?php else: ?>

                            <div class="admin-tabla-scroll">

                                <table class="admin-tabla">

                                    <thead>
                                        <tr>
                                            <th scope="col">Nombre</th>
                                            <th scope="col">Usuario</th>
                                            <th scope="col">Estado</th>
                                            <th scope="col">Acción</th>
                                        </tr>
                                    </thead>

                                    <tbody>

                                        <?php foreach ($grupo as $usuario): ?>

                                            <?php
                                            $activo = aulaAdminActiva(
                                                $usuario['activo']
                                            );
                                            ?>

                                            <tr>

                                                <td>
                                                    <?= escaparApoderados(
                                                        $usuario['nombre'] . ' ' .
                                                        $usuario['apellido']
                                                    ) ?>
                                                </td>

                                                <td>
                                                    <?= escaparApoderados($usuario['username']) ?>
                                                </td>

                                                <td class="<?= $activo
                                                    ? 'admin-activo'
                                                    : 'admin-inactivo' ?>">

                                                    <?= $activo ? 'Activo' : 'Inactivo' ?>

                                                </td>

<td>

    <form method="POST"
          class="admin-retirar-integrante"
          data-nombre="<?= escaparApoderados(
              $usuario['nombre'] . ' ' .
              $usuario['apellido']
          ) ?>">

        <input type="hidden"
               name="csrf_token"
               value="<?= escaparApoderados($_SESSION['csrf_admin_aulas']) ?>">

        <input type="hidden"
               name="accion"
               value="retirar_integrante">

        <input type="hidden"
               name="aula_id"
               value="<?= (int) $aula['id'] ?>">

        <input type="hidden"
               name="usuario_id"
               value="<?= (int) $usuario['id'] ?>">

        <input type="hidden"
               name="rol"
               value="<?= escaparApoderados($rol) ?>">

        <button type="submit"
                class="admin-papelera"
                title="Retirar integrante"
                aria-label="Retirar <?= escaparApoderados(
                    $usuario['nombre'] . ' ' .
                    $usuario['apellido']
                ) ?>">

            <svg viewBox="0 0 24 24"
                 width="19"
                 height="19"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.7"
                 stroke-linecap="round"
                 stroke-linejoin="round"
                 aria-hidden="true">

                <path d="M3 6h18"/>
                <path d="M8 6V4h8v2"/>
                <path d="M19 6l-1 14H6L5 6"/>
                <path d="M10 10v6"/>
                <path d="M14 10v6"/>

            </svg>

        </button>

    </form>

</td>

                                            </tr>

                                        <?php endforeach; ?>

                                    </tbody>

                                </table>

                            </div>

                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

            <a class="admin-volver"
               href="<?= $vista === 'listado'
                   ? 'panel_admin.php'
                   : 'aulas_admin.php' ?>">

                &larr; <?= $vista === 'listado'
                    ? 'Volver al inicio'
                    : 'Volver a las aulas' ?>

            </a>

        </section>

    </main>

    <!-- Footer -->

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
