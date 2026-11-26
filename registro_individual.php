<?php

require_once __DIR__ . '/config/rol.php';
require_once __DIR__ . '/config/apoderados.php';
require_once __DIR__ . '/config/registro_usuarios.php';

exigirRol('admin');

header('Cache-Control: private, no-store');

require_once __DIR__ . '/config/conexion.php';

$adminId = (int) $_SESSION['usuario_id'];
$colegioId = (int) $_SESSION['colegio_id'];
$nombre = trim($_SESSION['nombre'] ?? '');

if (empty($_SESSION['csrf_registro_individual'])) {
    $_SESSION['csrf_registro_individual'] =
        bin2hex(random_bytes(32));
}

$errores = [];

$exito = $_SESSION['registro_individual_exito'] ?? null;

unset($_SESSION['registro_individual_exito']);

function campoRegistroIndividual(string $campo): string
{
    $valor = $_POST[$campo] ?? '';

    if (!is_string($valor)) {
        throw new InvalidArgumentException(
            'Los datos enviados no son válidos.'
        );
    }

    return $campo === 'password' ||
           $campo === 'apoderado_password'
        ? $valor
        : trim($valor);
}

try {

    /* Verificar administrador */

    $stmt = $pdo->prepare("
        SELECT id
        FROM usuarios
        WHERE id = ?
          AND colegio_id = ?
          AND rol = 'admin'
          AND activo = TRUE
    ");

    $stmt->execute([$adminId, $colegioId]);

    if (!$stmt->fetchColumn()) {
        http_response_code(403);
        exit('Acceso no autorizado.');
    }

    /* Obtener aulas disponibles */

    $stmt = $pdo->prepare("
        SELECT id, nombre, asignatura_nombre
        FROM aulas
        WHERE colegio_id = ?
          AND activo = TRUE
        ORDER BY id
    ");

    $stmt->execute([$colegioId]);

    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Obtener cursos sin repetir */

    $cursos = [];

    foreach ($aulas as $aula) {

        $clave = normalizarTextoRegistro(
            $aula['nombre']
        );

        $cursos[$clave] = $aula['nombre'];
    }

    natcasesort($cursos);

    /* Obtener estudiantes disponibles */

    $stmt = $pdo->prepare("
        SELECT id, rut, nombre, apellido
        FROM usuarios
        WHERE colegio_id = ?
          AND rol = 'alumno'
          AND activo = TRUE
        ORDER BY nombre, apellido
    ");

    $stmt->execute([$colegioId]);

    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Procesar registro */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $csrf = campoRegistroIndividual('csrf_token');

        if (!hash_equals(
            $_SESSION['csrf_registro_individual'],
            $csrf
        )) {
            throw new InvalidArgumentException(
                'Solicitud no válida. Actualiza la página.'
            );
        }

        $rol = campoRegistroIndividual('rol');

        if (!in_array(
            $rol,
            ['alumno', 'profesor', 'apoderado'],
            true
        )) {
            throw new InvalidArgumentException(
                'Selecciona un tipo de usuario válido.'
            );
        }

        $datos = [
            'estudiantes' => [],
            'profesores' => [],
            'apoderados' => [],
            'errores' => []
        ];

        $registro = [
            'fila' => 1,
            'rol' => $rol,
            'rut' => normalizarRutRegistro(
                campoRegistroIndividual('rut')
            ),
            'nombre' => campoRegistroIndividual('nombre'),
            'apellido' => campoRegistroIndividual('apellido'),
            'username' => campoRegistroIndividual('username'),
            'password' => campoRegistroIndividual('password')
        ];

        /* Registrar estudiante */

        if ($rol === 'alumno') {

            $registro['curso'] =
                campoRegistroIndividual('curso');

            $datos['estudiantes'][] = $registro;

            /* Registrar apoderado simultáneamente */

            if (
                campoRegistroIndividual('vincular_apoderado')
                === '1'
            ) {

                $datos['apoderados'][] = [
                    'fila' => 1,
                    'rol' => 'apoderado',
                    'rut' => normalizarRutRegistro(
                        campoRegistroIndividual('apoderado_rut')
                    ),
                    'nombre' => campoRegistroIndividual(
                        'apoderado_nombre'
                    ),
                    'apellido' => campoRegistroIndividual(
                        'apoderado_apellido'
                    ),
                    'username' => campoRegistroIndividual(
                        'apoderado_username'
                    ),
                    'password' => campoRegistroIndividual(
                        'apoderado_password'
                    ),
                    'rut_estudiante' => $registro['rut']
                ];
            }
        }

        /* Registrar profesor */

        if ($rol === 'profesor') {

            $aulaId = filter_var(
                campoRegistroIndividual('aula_id'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            $aulaSeleccionada = null;

            foreach ($aulas as $aula) {

                if ((int) $aula['id'] === $aulaId) {
                    $aulaSeleccionada = $aula;
                    break;
                }
            }

            if (!$aulaSeleccionada) {
                throw new InvalidArgumentException(
                    'Selecciona un aula disponible.'
                );
            }

            $registro['curso'] =
                $aulaSeleccionada['nombre'];

            $registro['asignatura'] =
                $aulaSeleccionada['asignatura_nombre'] ?? '';

            $datos['profesores'][] = $registro;
        }

        /* Registrar apoderado individual */

        if ($rol === 'apoderado') {

            $registro['rut_estudiante'] =
                normalizarRutRegistro(
                    campoRegistroIndividual('rut_estudiante')
                );

            $datos['apoderados'][] = $registro;
        }

        /* Validar usando las funciones existentes */

        $pdo->beginTransaction();

        $pdo->exec(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'
        );

        $stmt = $pdo->prepare("
            SELECT id, nombre, asignatura_nombre
            FROM aulas
            WHERE colegio_id = ?
              AND activo = TRUE
            ORDER BY id
        ");

        $stmt->execute([$colegioId]);

        $snapshot = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot !== $aulas) {
            throw new InvalidArgumentException(
                'Las aulas han cambiado. Actualiza la página.'
            );
        }

        $resultado = validarDatosRegistro(
            $pdo,
            $colegioId,
            $datos
        );

        $pdo->commit();

        unset($datos, $registro);

        if (!$resultado['valido']) {

            $errores = $resultado['errores'];

        } else {

            /* Proteger contraseñas */

            foreach ($resultado['usuarios'] as &$usuario) {

                $usuario['password_hash'] = password_hash(
                    $usuario['password'],
                    PASSWORD_DEFAULT
                );

                unset($usuario['password']);
            }

            unset($usuario);

            $resultado['aulas_snapshot'] = $snapshot;

            /* Guardar usuarios y vinculaciones */

            $resumen = importarUsuariosRegistro(
                $pdo,
                $colegioId,
                $adminId,
                $resultado
            );

            $_SESSION['registro_individual_exito'] =
                $resumen;

            $_SESSION['csrf_registro_individual'] =
                bin2hex(random_bytes(32));

            header(
                'Location: registro_individual.php',
                true,
                303
            );

            exit;
        }
    }

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (!isset($aulas, $cursos, $estudiantes)) {
        error_log(
            'Error al cargar registro individual: ' .
            get_class($e)
        );

        http_response_code(500);

        exit('No se pudo cargar el formulario.');
    }

    $mensaje = $e instanceof InvalidArgumentException
        ? $e->getMessage()
        : 'No se pudo completar el registro. Inténtalo nuevamente.';

    $errores[] = [
        'hoja' => 'Registro',
        'fila' => 1,
        'mensaje' => $mensaje
    ];

    if (!$e instanceof InvalidArgumentException) {
        error_log(
            'Error de registro individual: ' .
            get_class($e)
        );
    }
}

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
    <script src="js/registro-individual.js" defer></script>

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
        <a href="registro_usuarios.php">Registro de usuarios</a>
        <span>/</span>
        <span>Registro individual</span>
    </div>

    <section class="av-welcome">

        <div class="av-welcome-text">

            <span class="av-eyebrow">ADMINISTRACIÓN</span>

            <h1>Registro individual</h1>

            <p>
                Registra usuarios, asigna sus aulas y
                establece vinculaciones familiares.
            </p>

        </div>

    </section>

    <?php if ($exito !== null): ?>

        <div class="admin-mensaje registro-exito"
             role="status">

            <strong>Registro completado correctamente.</strong>

            <p>
                <?= (int) $exito['usuarios'] ?> usuarios creados,
                <?= (int) $exito['asignaciones'] ?> asignaciones y
                <?= (int) $exito['vinculaciones'] ?> vinculaciones.
            </p>

        </div>

    <?php endif; ?>

    <?php if ($errores): ?>

        <div class="admin-mensaje admin-error"
             role="alert">

            <strong>No se pudo completar el registro.</strong>

            <ul>

                <?php foreach ($errores as $error): ?>

                    <li>
                        <?= escaparApoderados($error['hoja']) ?>:
                        <?= escaparApoderados($error['mensaje']) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <section class="admin-panel individual-panel">

        <form method="POST" id="form-registro-individual">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= escaparApoderados(
                       $_SESSION['csrf_registro_individual']
                   ) ?>">

            <h2>Tipo de usuario</h2>

            <div class="individual-roles">

                <label class="individual-rol">

                    <input type="radio"
                           name="rol"
                           value="alumno"
                           checked>

                    <span>Estudiante</span>

                </label>

                <label class="individual-rol">

                    <input type="radio"
                           name="rol"
                           value="profesor">

                    <span>Profesor</span>

                </label>

                <label class="individual-rol">

                    <input type="radio"
                           name="rol"
                           value="apoderado">

                    <span>Apoderado</span>

                </label>

            </div>

            <div class="individual-seccion">

                <h2>Datos personales</h2>

                <div class="individual-grid">

                    <label>
                        Nombre
                        <input type="text"
                               name="nombre"
                               maxlength="100"
                               placeholder="Nombre"
                               required>
                    </label>

                    <label>
                        Apellido
                        <input type="text"
                               name="apellido"
                               maxlength="100"
                               placeholder="Apellido"
                               required>
                    </label>

                    <label>
                        RUT
                        <input type="text"
                               name="rut"
                               maxlength="15"
                               placeholder="12345678-5"
                               required>
                    </label>

                    <label>
                        Nombre de usuario
                        <input type="text"
                               name="username"
                               minlength="3"
                               maxlength="50"
                               pattern="[a-zA-Z0-9._\-]{3,50}"
                               autocomplete="off"
                               required>
                    </label>

                    <label>
                        Contraseña
                        <input type="password"
                               name="password"
                               minlength="8"
                               maxlength="72"
                               autocomplete="new-password"
                               placeholder="Mínimo 8 caracteres"
                               required>
                    </label>

                </div>

            </div>

            <!-- Datos del estudiante -->

            <fieldset id="campos-alumno"
                      class="individual-seccion">

                <h2>Información académica</h2>

                <div class="individual-grid">

                    <label>
                        Curso

                        <select name="curso" required>

                            <option value="">
                                Seleccionar curso
                            </option>

                            <?php foreach ($cursos as $curso): ?>

                                <option value="<?= escaparApoderados($curso) ?>">
                                    <?= escaparApoderados($curso) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                </div>

                <p class="individual-ayuda">
                    El estudiante será asignado a todas las
                    asignaturas activas del curso seleccionado.
                </p>

                <label class="individual-check">

                    <input type="checkbox"
                           name="vincular_apoderado"
                           id="vincular-apoderado"
                           value="1"
                           checked>

                    Registrar y vincular apoderado inmediatamente

                </label>

                <fieldset id="datos-apoderado"
                          class="individual-apoderado">

                    <h2>Datos del apoderado</h2>

                    <div class="individual-grid">

                        <label>
                            Nombre
                            <input type="text"
                                   name="apoderado_nombre"
                                   maxlength="100"
                                   required>
                        </label>

                        <label>
                            Apellido
                            <input type="text"
                                   name="apoderado_apellido"
                                   maxlength="100"
                                   required>
                        </label>

                        <label>
                            RUT
                            <input type="text"
                                   name="apoderado_rut"
                                   maxlength="15"
                                   placeholder="12345678-5"
                                   required>
                        </label>

                        <label>
                            Nombre de usuario
                            <input type="text"
                                   name="apoderado_username"
                                   minlength="3"
                                   maxlength="50"
                                   pattern="[a-zA-Z0-9._\-]{3,50}"
                                   autocomplete="off"
                                   required>
                        </label>

                        <label>
                            Contraseña
                            <input type="password"
                                   name="apoderado_password"
                                   minlength="8"
                                   maxlength="72"
                                   autocomplete="new-password"
                                   required>
                        </label>

                    </div>

                    <p class="individual-ayuda">
                        Ambas cuentas serán creadas y vinculadas
                        automáticamente.
                    </p>

                </fieldset>

            </fieldset>

            <!-- Datos del profesor -->

            <fieldset id="campos-profesor"
                      class="individual-seccion"
                      hidden
                      disabled>

                <h2>Asignación académica</h2>

                <div class="individual-grid">

                    <label>
                        Curso y asignatura

                        <select name="aula_id" required>

                            <option value="">
                                Seleccionar aula
                            </option>

                            <?php foreach ($aulas as $aula): ?>

                                <option value="<?= (int) $aula['id'] ?>">

                                    <?= escaparApoderados(
                                        $aula['nombre'] . ' - ' .
                                        ($aula['asignatura_nombre'] ?? '')
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                </div>

            </fieldset>

            <!-- Apoderado de estudiante existente -->

            <fieldset id="campos-apoderado"
                      class="individual-seccion"
                      hidden
                      disabled>

                <h2>Vinculación familiar</h2>

                <div class="individual-grid">

                    <label>
                        Estudiante asociado

                        <select name="rut_estudiante" required>

                            <option value="">
                                Seleccionar estudiante
                            </option>

                            <?php foreach ($estudiantes as $estudiante): ?>

                                <option value="<?= escaparApoderados(
                                    $estudiante['rut']
                                ) ?>">

                                    <?= escaparApoderados(
                                        $estudiante['nombre'] . ' ' .
                                        $estudiante['apellido'] . ' - ' .
                                        $estudiante['rut']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </label>

                </div>

                <p class="individual-ayuda">
                    La vinculación será confirmada automáticamente.
                </p>

            </fieldset>

            <div class="individual-acciones">

                <a href="registro_usuarios.php"
                   class="admin-editar">
                    Cancelar
                </a>

                <button type="submit"
                        class="admin-btn"
                        id="guardar-individual">

                    Registrar estudiante y apoderado

                </button>

            </div>

        </form>

    </section>

    <a href="registro_usuarios.php"
       class="admin-volver">

        &larr; Volver a registro de usuarios

    </a>

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
