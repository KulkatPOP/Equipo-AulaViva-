<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';

exigirRol('admin');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/config/registro_usuarios.php';

if (empty($_SESSION['csrf_registro_usuarios'])) {
    $_SESSION['csrf_registro_usuarios'] =
        bin2hex(random_bytes(32));
}

$resultadoImportacion = null;
$errorImportacion = '';

if (isset($_SESSION['importacion_usuarios']) &&
    ($_SESSION['importacion_usuarios']['expira_en'] ?? 0) <= time()) {
    unset($_SESSION['importacion_usuarios']);
}

try {
    require_once __DIR__ . '/config/conexion.php';

    $admin = consultaApoderados($pdo, "
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND colegio_id = ?
          AND rol = 'admin'
          AND activo = TRUE
    ", [
        (int) $_SESSION['usuario_id'],
        (int) $_SESSION['colegio_id']
    ])->fetchColumn();

    if ($admin === false) {
        http_response_code(403);
        exit('No tienes permiso para acceder.');
    }

} catch (Throwable $e) {
    error_log('Panel administrador: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo cargar el panel.');
}

/* Procesar archivo Excel y confirmar importación */
$exitoImportacion = $_SESSION['registro_exito'] ?? null;
unset($_SESSION['registro_exito']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!is_string($csrf) || !hash_equals($_SESSION['csrf_registro_usuarios'], $csrf)) {
            throw new InvalidArgumentException('Solicitud no válida. Actualiza la página.');
        }
        $accion = $_POST['accion'] ?? '';
        $colegioId = (int) $_SESSION['colegio_id'];
        $adminId = (int) $_SESSION['usuario_id'];

        if ($accion === 'confirmar') {
            $pendiente = $_SESSION['importacion_usuarios'] ?? null;
            $token = $_POST['token_importacion'] ?? '';
            if (!$pendiente || !is_string($token) ||
                !hash_equals($pendiente['token'], $token) ||
                $pendiente['expira_en'] <= time() ||
                $pendiente['admin_id'] !== $adminId ||
                $pendiente['colegio_id'] !== $colegioId ||
                !isset($pendiente['datos']['aulas_snapshot'])) {
                throw new InvalidArgumentException('Importación vencida o no válida. Vuelve a validar el Excel.');
            }
            unset($_SESSION['importacion_usuarios']);
            $_SESSION['registro_exito'] = importarUsuariosRegistro(
                $pdo, $colegioId, $adminId, $pendiente['datos']
            );
            header('Location: registro_usuarios.php', true, 303);
            exit;
        }
        if ($accion !== 'validar') {
            throw new InvalidArgumentException('Acción no válida.');
        }
        unset($_SESSION['importacion_usuarios']);
        $ruta = validarArchivoRegistro($_FILES['archivo_excel'] ?? []);
        $datos = leerExcelRegistro($ruta);
        // Capturar aulas y validar bajo una misma vista de PostgreSQL.
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $stmt = $pdo->prepare('SELECT id, nombre, asignatura_nombre FROM aulas WHERE colegio_id = ? AND activo = TRUE ORDER BY id');
        $stmt->execute([$colegioId]);
        $snapshot = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $resultadoImportacion = validarDatosRegistro($pdo, $colegioId, $datos);
        $pdo->commit();
        unset($datos);
        if ($resultadoImportacion['valido']) {
            foreach ($resultadoImportacion['usuarios'] as &$usuario) {
                $usuario['password_hash'] = password_hash($usuario['password'], PASSWORD_DEFAULT);
                unset($usuario['password']);
            }
            unset($usuario);
            $resultadoImportacion['aulas_snapshot'] = $snapshot;
            $_SESSION['importacion_usuarios'] = [
                'admin_id' => $adminId,
                'colegio_id' => $colegioId,
                'expira_en' => time() + 600,
                'token' => bin2hex(random_bytes(32)),
                'datos' => $resultadoImportacion
            ];
        }
        unset($resultadoImportacion['usuarios']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $resultadoImportacion = null;
        if ($e instanceof InvalidArgumentException) {
            $errorImportacion = $e->getMessage();
        } else {
            error_log('Error de registro: ' . get_class($e));
            $errorImportacion = 'No se pudo completar la operación. Vuelve a validar el Excel.';
        }
    }
}

$nombre = trim($_SESSION['nombre'] ?? '');

$secciones = [
    [
    'titulo' => 'Usuarios',
    'descripcion' => 'Gestiona profesores, estudiantes y apoderados.',
    'url' => 'usuarios_admin.php'
],
    [
    'titulo' => 'Aulas',
    'descripcion' => 'Administra cursos, asignaturas y sus integrantes.',
    'url' => 'aulas_admin.php'
],
    [
        'titulo' => 'Vinculaciones',
        'descripcion' => 'Asocia apoderados con sus estudiantes.',
        'url' => 'vinculos_apoderado.php'
    ],
[
    'titulo' => 'Registro de usuarios',
    'descripcion' => 'Incorpora usuarios de forma individual o masiva.',
    'url' => 'registro_usuarios.php'
]
];

?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Registro de usuarios | AulaIA</title>

    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/admin.css">

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap"
          rel="stylesheet">

    <script src="js/dashboard.js" defer></script>
    <script src="js/registro-usuarios.js" defer></script>

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
                     fill="none"
                     stroke="currentColor"
                     stroke-width="2"
                     aria-hidden="true">
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
                     fill="none"
                     stroke="currentColor"
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

            <a href="panel_admin.php">
                Inicio
            </a>

            <span class="av-menu-label">ADMINISTRACIÓN</span>

            <?php foreach ($secciones as $seccion): ?>

                <?php if ($seccion['url'] !== null): ?>

                    <a href="<?= escaparApoderados($seccion['url']) ?>"
                       <?php if ($seccion['url'] === 'registro_usuarios.php'): ?>
                           class="av-menu-active" aria-current="page"
                       <?php endif; ?>>
                        <?= escaparApoderados($seccion['titulo']) ?>
                    </a>

                <?php endif; ?>

            <?php endforeach; ?>

        </nav>

        <div class="av-sidebar-bottom">
            <a href="logout.php">Cerrar sesión</a>
        </div>

    </dialog>

    <!-- Contenido principal -->


<main class="av-main">

    <div class="av-breadcrumb">
        <a href="panel_admin.php">Inicio</a>
        <span>/</span>
        <span>Registro de usuarios</span>
    </div>

    <section class="av-welcome">

        <div class="av-welcome-text">

            <span class="av-eyebrow">
                ADMINISTRACIÓN
            </span>

            <h1>Registro de usuarios</h1>

            <p>
                Incorpora profesores, estudiantes y
                apoderados a tu establecimiento.
            </p>

        </div>

    </section>

    <section class="av-courses">

        <div class="admin-grid registro-opciones">

            <!-- Registro masivo -->

            <article class="admin-card registro-card">

                <svg class="registro-icono registro-verde"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.6"
                     stroke-linecap="round"
                     stroke-linejoin="round"
                     aria-hidden="true">

                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                    <path d="M14 2v6h6"/>
                    <rect x="7" y="11" width="10" height="7" rx="1"/>
                    <path d="M7 14.5h10M12 11v7"/>

                </svg>

                <h3>Registro masivo</h3>

                <p>
                    Registra múltiples usuarios, asigna aulas
                    y vincula apoderados mediante un archivo Excel.
                </p>

                <a href="#importar-excel"
                   class="admin-btn">
                    Importar Excel
                </a>

            </article>

            <!-- Registro individual -->

            <article class="admin-card registro-card">

                <svg class="registro-icono registro-azul"
                     viewBox="0 0 24 24"
                     fill="none"
                     stroke="currentColor"
                     stroke-width="1.6"
                     stroke-linecap="round"
                     stroke-linejoin="round"
                     aria-hidden="true">

                    <circle cx="9" cy="7" r="4"/>
                    <path d="M2 21v-2a7 7 0 0 1 14 0v2"/>
                    <path d="M19 8v6M16 11h6"/>

                </svg>

                <h3>Registro individual</h3>

                <p>
                    Crea cuentas individuales y gestiona
                    sus aulas o vinculaciones familiares.
                </p>

                <a href="registro_individual.php"
   class="admin-btn">
    Registrar usuario
</a>

            </article>

        </div>

        <?php if ($exitoImportacion !== null): ?>
            <section class="admin-panel registro-resultado" id="resultado-importacion">
                <h2>Importación completada</h2>
                <div class="admin-mensaje" role="status">Los usuarios se registraron correctamente.</div>
                <div class="registro-detalles">
                    <p><strong><?= (int) $exitoImportacion['usuarios'] ?></strong> usuarios creados.</p>
                    <p><strong><?= (int) $exitoImportacion['asignaciones'] ?></strong> asignaciones de aulas.</p>
                    <p><strong><?= (int) $exitoImportacion['vinculaciones'] ?></strong> vinculaciones familiares.</p>
                </div>
                <a class="admin-btn" href="usuarios_admin.php">Ver usuarios</a>
            </section>
        <?php endif; ?>

        <?php if ($errorImportacion !== ''): ?>
            <div class="admin-mensaje admin-error" role="alert">
                <?= escaparApoderados($errorImportacion) ?>
            </div>
        <?php endif; ?>

        <?php if ($resultadoImportacion !== null): ?>
            <section class="admin-panel registro-resultado" id="resultado-importacion">
                <h2>Resultado de la validación</h2>

                <?php if ($resultadoImportacion['valido']): ?>
                    <?php $resumen = $resultadoImportacion['resumen']; ?>
                    <div class="registro-resumen">
                        <?php foreach (['estudiantes' => 'Estudiantes', 'apoderados' => 'Apoderados', 'profesores' => 'Profesores'] as $clave => $etiqueta): ?>
                            <article>
                                <span><?= escaparApoderados($etiqueta) ?></span>
                                <strong><?= (int) $resumen[$clave] ?></strong>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="admin-mensaje" role="status">
                        El archivo se ha validado correctamente. No se encontraron errores.
                    </div>
                    <div class="registro-detalles">
                        <p><strong><?= (int) $resumen['usuarios'] ?></strong> usuarios nuevos.</p>
                        <p><strong><?= (int) $resumen['asignaciones'] ?></strong> asignaciones de aulas.</p>
                        <p><strong><?= (int) $resumen['vinculaciones'] ?></strong> vinculaciones familiares.</p>
                    </div>
                    <div class="registro-confirmacion">
                        <a href="registro_usuarios.php" class="admin-editar">Volver</a>
                        <form method="POST">
                            <input type="hidden" name="accion" value="confirmar">
                            <input type="hidden" name="csrf_token" value="<?= escaparApoderados($_SESSION['csrf_registro_usuarios']) ?>">
                            <input type="hidden" name="token_importacion" value="<?= escaparApoderados($_SESSION['importacion_usuarios']['token']) ?>">
                            <button type="submit" class="admin-btn">Confirmar importación</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="admin-mensaje admin-error" role="alert">
                        Se encontraron errores en el archivo. Corrígelos antes de continuar.
                    </div>
                    <div class="admin-tabla-scroll">
                        <table class="admin-tabla">
                            <thead><tr><th>Hoja</th><th>Fila</th><th>Error</th></tr></thead>
                            <tbody>
                                <?php foreach ($resultadoImportacion['errores'] as $error): ?>
                                    <tr>
                                        <td><?= escaparApoderados($error['hoja']) ?></td>
                                        <td><?= (int) $error['fila'] ?: '—' ?></td>
                                        <td><?= escaparApoderados($error['mensaje']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Importación Excel -->

        <section id="importar-excel"
                 class="registro-importacion">

            <div class="admin-seccion-header">

                <h2>Importar usuarios</h2>

            </div>

            <div class="admin-panel registro-panel">

                <div class="registro-plantilla">

                    <div class="registro-plantilla-info">

                        <svg class="registro-icono registro-verde"
                             viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.6"
                             stroke-linecap="round"
                             stroke-linejoin="round"
                             aria-hidden="true">

                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <path d="M14 2v6h6"/>
                            <path d="M8 12l6 6M14 12l-6 6"/>

                        </svg>

                        <div>

                            <h3>Plantilla de registro</h3>

                            <p>
                                Completa las hojas de estudiantes,
                                apoderados y profesores.
                            </p>

                        </div>

                    </div>

                    <a href="plantilla_usuarios.php"
                       class="registro-descargar">

                        <svg viewBox="0 0 24 24"
                             width="20"
                             height="20"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.8"
                             stroke-linecap="round"
                             stroke-linejoin="round"
                             aria-hidden="true">

                            <path d="M12 3v12"/>
                            <path d="m7 10 5 5 5-5"/>
                            <path d="M4 16v5h16v-5"/>

                        </svg>

                        Descargar plantilla

                    </a>

                </div>

                <form id="form-importar-excel"
                      method="POST"
                      enctype="multipart/form-data">
                      <input type="hidden"
       name="csrf_token"
       value="<?= escaparApoderados(
           $_SESSION['csrf_registro_usuarios']
       ) ?>">

<input type="hidden"
       name="accion"
       value="validar">

                    <div class="registro-dropzone"
                         id="zona-excel">

                        <svg class="registro-upload-icon"
                             viewBox="0 0 24 24"
                             fill="none"
                             stroke="currentColor"
                             stroke-width="1.5"
                             stroke-linecap="round"
                             stroke-linejoin="round"
                             aria-hidden="true">

                            <path d="M12 16V4"/>
                            <path d="m7 9 5-5 5 5"/>
                            <path d="M4 15v5h16v-5"/>

                        </svg>

                        <h3>Arrastra tu archivo Excel aquí</h3>

                        <p>
                            Formato permitido: .xlsx
                        </p>

                        <label for="archivo-excel"
                               class="registro-seleccionar">

                            Seleccionar archivo

                        </label>

                        <input type="file"
                               id="archivo-excel"
                               name="archivo_excel"
                               accept=".xlsx"
                               class="registro-file"
                               required>

                        <span id="nombre-archivo"
                              class="registro-archivo-nombre"
                              aria-live="polite">
                            Ningún archivo seleccionado
                        </span>

                    </div>

                    <div id="error-excel"
                         class="admin-mensaje admin-error"
                         role="alert"
                         hidden>
                    </div>

                    <div class="registro-form-acciones">

                        <button type="submit"
                                id="validar-excel"
                                class="admin-btn"
                                disabled>

                            Validar archivo

                        </button>

                    </div>

                </form>

            </div>

        </section>

        <a href="panel_admin.php"
           class="admin-volver">

            &larr; Volver al inicio

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
