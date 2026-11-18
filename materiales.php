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

$volver = $esProfesor ? 'aula.php?id=' . $aulaId : 'mis_cursos.php';
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
                    "WITH aula_autorizada AS ($consultaSubida)
                     INSERT INTO materiales (aula_id, titulo, nombre_archivo, contenido)
                     SELECT id, :titulo, :nombre, decode(:contenido, 'base64') FROM aula_autorizada"
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
if ($esProfesor) {
    $_SESSION['csrf_subir_material'] ??= bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materiales | Aula Viva</title>
    <link rel="stylesheet" href="css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="panel-docente">
    <header>
        <a href="<?= $esProfesor ? 'mis_aulas.php' : 'mis_cursos.php' ?>" class="logo">
            <img src="img/Logo.png" alt="">
            <span class="title-header">Aula Viva</span>
        </a>
        <span class="panel-identidad"><?= escaparMaterial($_SESSION['nombre'] ?? '') ?></span>
        <nav aria-label="Navegación">
            <a href="<?= $esProfesor ? 'mis_aulas.php' : 'mis_cursos.php' ?>" class="nav-link"><?= $esProfesor ? 'Mis aulas' : 'Mis cursos' ?></a>
            <a href="logout.php" class="nav-link">Cerrar sesión</a>
        </nav>
    </header>
    <main class="panel-contenido">
        <div class="panel-intro panel-intro-aula">
            <a class="panel-volver" href="<?= escaparMaterial($volver) ?>">← Volver</a>
            <h1>Materiales</h1>
            <?php if (isset($aula['nombre'])): ?>
                <p class="panel-resumen"><?= escaparMaterial($aula['nombre']) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($errorCarga): ?>
            <p class="curso panel-aviso panel-aviso-error" role="alert">No se pudo completar la solicitud de materiales. Inténtalo nuevamente.</p>
        <?php else: ?>
            <?php if ($materialSubido): ?>
                <p class="panel-resultado panel-resultado-exito" role="status">Material subido correctamente.</p>
            <?php endif; ?>
            <?php if ($esProfesor): ?>
                <form class="curso panel-asignacion panel-subir-material" method="post" enctype="multipart/form-data">
                    <h2 class="panel-subtitulo">Subir material</h2>
                    <?php if ($errorSubida !== ''): ?>
                        <p class="panel-resultado panel-resultado-error" role="alert"><?= escaparMaterial($errorSubida) ?></p>
                    <?php endif; ?>
                    <input type="hidden" name="aula_id" value="<?= $aulaId ?>">
                    <input type="hidden" name="csrf_token" value="<?= escaparMaterial($_SESSION['csrf_subir_material']) ?>">
                    <label for="titulo-material">Título</label>
                    <input id="titulo-material" name="titulo" maxlength="120" required>
                    <label for="archivo-material">Archivo PDF (hasta 10 MiB)</label>
                    <input id="archivo-material" name="archivo" type="file" accept="application/pdf,.pdf" required>
                    <button class="panel-boton" type="submit">Subir material</button>
                </form>
            <?php endif; ?>
            <?php if (!$materiales): ?>
                <p class="curso panel-aviso">No hay materiales disponibles para este curso.</p>
            <?php else: ?>
                <ul class="panel-tarjetas panel-tarjetas-aulas" role="list">
                    <?php foreach ($materiales as $material): ?>
                        <li class="curso">
                            <h2><?= escaparMaterial($material['titulo']) ?></h2>
                            <div class="panel-acciones-material">
                                <a class="panel-boton" href="archivo_material.php?material_id=<?= (int) $material['id'] ?>"
                                   aria-label="Abrir <?= escaparMaterial($material['titulo']) ?>">Abrir</a>
                                <a class="panel-volver" href="archivo_material.php?material_id=<?= (int) $material['id'] ?>&amp;descargar=1"
                                   aria-label="Descargar <?= escaparMaterial($material['titulo']) ?>">Descargar</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
