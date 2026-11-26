<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/* Límites de importación */

const REGISTRO_MAX_ARCHIVO = 5 * 1024 * 1024;
const REGISTRO_MAX_FILAS = 1000;
const REGISTRO_MAX_DESCOMPRIMIDO = 40 * 1024 * 1024;

/* Normalizar cursos y asignaturas */

function normalizarTextoRegistro(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');

    $texto = strtr($texto, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u'
    ]);

    return preg_replace('/\s+/u', ' ', $texto);
}

/* Normalizar RUT */

function normalizarRutRegistro(string $rut): string
{
    return strtoupper(
        str_replace(
            ['.', '-', ' '],
            '',
            trim($rut)
        )
    );
}

/* Validar RUT chileno */

function validarRutRegistro(string $rut): bool
{
    $rut = normalizarRutRegistro($rut);

    if (!preg_match('/^[0-9]{7,8}[0-9K]$/', $rut)) {
        return false;
    }

    $numero = substr($rut, 0, -1);
    $digito = substr($rut, -1);

    $suma = 0;
    $multiplicador = 2;

    for ($i = strlen($numero) - 1; $i >= 0; $i--) {

        $suma += (int) $numero[$i] * $multiplicador;

        $multiplicador++;

        if ($multiplicador > 7) {
            $multiplicador = 2;
        }
    }

    $resultado = 11 - ($suma % 11);

    $esperado = match ($resultado) {
        11 => '0',
        10 => 'K',
        default => (string) $resultado
    };

    return $digito === $esperado;
}

/* Revisar archivo recibido */

function validarArchivoRegistro(array $archivo): string
{
    if (
        ($archivo['error'] ?? UPLOAD_ERR_NO_FILE)
        !== UPLOAD_ERR_OK
    ) {
        throw new InvalidArgumentException(
            'No se pudo recibir el archivo Excel.'
        );
    }

    $ruta = $archivo['tmp_name'] ?? '';
    $nombre = $archivo['name'] ?? '';

    if (!is_uploaded_file($ruta)) {
        throw new InvalidArgumentException(
            'Archivo no válido.'
        );
    }

    if (
        strtolower(pathinfo($nombre, PATHINFO_EXTENSION))
        !== 'xlsx'
    ) {
        throw new InvalidArgumentException(
            'Solo se permiten archivos .xlsx.'
        );
    }

    $tamano = filesize($ruta);

    if (
        $tamano === false ||
        $tamano === 0 ||
        $tamano > REGISTRO_MAX_ARCHIVO
    ) {
        throw new InvalidArgumentException(
            'El archivo debe tener un tamaño máximo de 5 MB.'
        );
    }

    // Comprobar el contenido comprimido del Excel.

    $zip = new ZipArchive();

    if ($zip->open($ruta) !== true) {
        throw new InvalidArgumentException(
            'El archivo Excel está dañado o no es válido.'
        );
    }

    try {

        $total = 0;

        if ($zip->numFiles > 1000) {
            throw new InvalidArgumentException(
                'El archivo contiene demasiados elementos.'
            );
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {

            $elemento = $zip->statIndex($i);

            if ($elemento === false) {
                throw new InvalidArgumentException(
                    'No se pudo revisar el archivo.'
                );
            }

            $total += $elemento['size'];

            if ($total > REGISTRO_MAX_DESCOMPRIMIDO) {
                throw new InvalidArgumentException(
                    'El contenido del Excel es demasiado grande.'
                );
            }
        }

        if (
            $zip->locateName('[Content_Types].xml') === false ||
            $zip->locateName('xl/workbook.xml') === false
        ) {
            throw new InvalidArgumentException(
                'El archivo no tiene una estructura Excel válida.'
            );
        }

    } finally {

        $zip->close();
    }

    return $ruta;
}

/* Leer las tres hojas */

function leerExcelRegistro(string $ruta): array
{
    $estructura = [

        'Estudiantes' => [
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'Curso'
        ],

        'Apoderados' => [
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'RUT estudiante'
        ],

        'Profesores' => [
            'RUT',
            'Nombre',
            'Apellido',
            'Usuario',
            'Contraseña',
            'Curso',
            'Asignatura'
        ]
    ];

    $lector = new Xlsx();

    $lector->setReadDataOnly(true);
    $lector->setReadEmptyCells(false);

    $informacion = $lector->listWorksheetInfo($ruta);

    $nombres = array_column(
        $informacion,
        'worksheetName'
    );

    if (
        count($nombres) !== count($estructura) ||
        array_diff(array_keys($estructura), $nombres)
    ) {
        throw new InvalidArgumentException(
            'El Excel debe contener las tres hojas de la plantilla.'
        );
    }

    foreach ($informacion as $info) {

        $maxColumnas = count(
            $estructura[$info['worksheetName']]
        );

        if (
            $info['totalRows'] > REGISTRO_MAX_FILAS + 1 ||
            $info['totalColumns'] > $maxColumnas
        ) {
            throw new InvalidArgumentException(
                'La hoja ' . $info['worksheetName'] .
                ' supera las dimensiones permitidas.'
            );
        }
    }

    $excel = $lector->load($ruta);

    $resultado = [
        'estudiantes' => [],
        'apoderados' => [],
        'profesores' => [],
        'errores' => []
    ];

    try {

        foreach ($estructura as $nombreHoja => $columnas) {

            $hoja = $excel->getSheetByName($nombreHoja);

            $clave = strtolower($nombreHoja);

            // Validar encabezados.

            foreach ($columnas as $indice => $encabezado) {

                $letra = Coordinate::stringFromColumnIndex(
                    $indice + 1
                );

                $valor = $hoja->getCell(
                    $letra . '1'
                )->getValue();

                if ($valor !== $encabezado) {
                    throw new InvalidArgumentException(
                        'Encabezados incorrectos en la hoja ' .
                        $nombreHoja . '.'
                    );
                }
            }

            $ultimaFila = $hoja->getHighestDataRow();

            for ($fila = 2; $fila <= $ultimaFila; $fila++) {

                $datos = [];
                $formula = false;

                foreach ($columnas as $indice => $columna) {

                    $letra = Coordinate::stringFromColumnIndex(
                        $indice + 1
                    );

                    $celda = $hoja->getCell(
                        $letra . $fila
                    );

                    if (
                        $celda->getDataType()
                        === DataType::TYPE_FORMULA
                    ) {
                        $formula = true;
                        $datos[] = '';
                        continue;
                    }

                    $valor = $celda->getValue();

                    if (
                        $valor !== null &&
                        !is_scalar($valor)
                    ) {
                        $formula = true;
                        $datos[] = '';
                        continue;
                    }

                    $datos[] = $indice === 4
                        ? (string) $valor
                        : trim((string) $valor);
                }

                if ($formula) {

                    $resultado['errores'][] = [
                        'hoja' => $nombreHoja,
                        'fila' => $fila,
                        'mensaje' => 'No se permiten fórmulas ni valores complejos.'
                    ];

                    continue;
                }

                $tieneDatos = array_filter(
                    $datos,
                    static fn($valor) => $valor !== ''
                );

                if (!$tieneDatos) {
                    continue;
                }

                $registro = [
                    'fila' => $fila,
                    'rut' => normalizarRutRegistro($datos[0]),
                    'nombre' => $datos[1],
                    'apellido' => $datos[2],
                    'username' => $datos[3],
                    'password' => $datos[4]
                ];

                if ($nombreHoja === 'Estudiantes') {

                    $registro['rol'] = 'alumno';
                    $registro['curso'] = $datos[5];

                } elseif ($nombreHoja === 'Apoderados') {

                    $registro['rol'] = 'apoderado';

                    $registro['rut_estudiante'] =
                        normalizarRutRegistro($datos[5]);

                } else {

                    $registro['rol'] = 'profesor';
                    $registro['curso'] = $datos[5];
                    $registro['asignatura'] = $datos[6];
                }

                $resultado[$clave][] = $registro;
            }
        }

    } finally {

        $excel->disconnectWorksheets();
    }

    return $resultado;
}

/* Validar registros antes de importar */

function validarDatosRegistro(
    PDO $pdo,
    int $colegioId,
    array $datos
): array {

    $errores = $datos['errores'];

    $usuarios = [];
    $nombresUsuario = [];
    $estudiantesDisponibles = [];
    $vinculaciones = [];
    $asignaciones = [];
    $existentes = [];
    $usuariosExistentes = [];

    /* Obtener usuarios existentes */

    $stmt = $pdo->query("
        SELECT rut, username
        FROM usuarios
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $usuario) {

        $existentes[
            normalizarRutRegistro($usuario['rut'])
        ] = true;

        $usuariosExistentes[
            mb_strtolower($usuario['username'])
        ] = true;
    }

    /* Obtener estudiantes del establecimiento */

    $stmt = $pdo->prepare("
        SELECT id, rut
        FROM usuarios
        WHERE colegio_id = ?
          AND rol = 'alumno'
          AND activo = TRUE
    ");

    $stmt->execute([$colegioId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $usuario) {

        $estudiantesDisponibles[
            normalizarRutRegistro($usuario['rut'])
        ] = true;
    }

    /* Obtener aulas activas */

    $stmt = $pdo->prepare("
        SELECT id, nombre, asignatura_nombre
        FROM aulas
        WHERE colegio_id = ?
          AND activo = TRUE
        ORDER BY id
    ");

    $stmt->execute([$colegioId]);

    $aulas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cursos = [];
    $asignaturas = [];

    foreach ($aulas as $aula) {

        $curso = normalizarTextoRegistro(
            $aula['nombre']
        );

        $asignatura = normalizarTextoRegistro(
            $aula['asignatura_nombre'] ?? ''
        );

        $cursos[$curso][] = (int) $aula['id'];

        $asignaturas[$curso][$asignatura][] =
            (int) $aula['id'];
    }

    /* Registrar errores */

    $agregarError = static function (
        string $hoja,
        int $fila,
        string $mensaje
    ) use (&$errores): void {

        $errores[] = [
            'hoja' => $hoja,
            'fila' => $fila,
            'mensaje' => $mensaje
        ];
    };

    /* Validar las tres hojas */

    foreach ([
        'estudiantes' => 'Estudiantes',
        'profesores' => 'Profesores',
        'apoderados' => 'Apoderados'
    ] as $clave => $hoja) {

        foreach ($datos[$clave] as $registro) {

            $fila = $registro['fila'];
            $rut = $registro['rut'];
            $rol = $registro['rol'];

            $username = mb_strtolower(
                $registro['username']
            );

            $erroresAntes = count($errores);

            /* Datos obligatorios */

            foreach ([
                'rut',
                'nombre',
                'apellido',
                'username',
                'password'
            ] as $campo) {

                if (trim($registro[$campo]) === '') {

                    $agregarError(
                        $hoja,
                        $fila,
                        'Faltan datos obligatorios.'
                    );

                    break;
                }
            }

            /* Validar RUT */

            if (!validarRutRegistro($rut)) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El RUT no es válido.'
                );
            }

            /* Validar nombre y apellido */

            if (
                mb_strlen($registro['nombre']) > 100 ||
                mb_strlen($registro['apellido']) > 100
            ) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El nombre o apellido es demasiado largo.'
                );
            }

            /* Validar nombre de usuario */

            if (
                !preg_match(
                    '/^[a-zA-Z0-9._-]{3,50}$/D',
                    $registro['username']
                )
            ) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El usuario debe tener entre 3 y 50 caracteres válidos.'
                );
            }

            /* Validar contraseña */

            if (
                strlen($registro['password']) < 8 ||
                strlen($registro['password']) > 72 ||
                str_contains($registro['password'], "\0")
            ) {

                $agregarError(
                    $hoja,
                    $fila,
                    'La contraseña debe tener entre 8 y 72 bytes y no contener caracteres nulos.'
                );
            }

            /* Detectar usuarios existentes */

            if (isset($existentes[$rut])) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El RUT ya está registrado.'
                );
            }

            if (isset($usuariosExistentes[$username])) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El nombre de usuario ya está registrado.'
                );
            }

            /* Detectar usuarios repetidos en Excel */

            if (isset($usuarios[$rut])) {

                $anterior = $usuarios[$rut];

                $mismoUsuario =
                    $anterior['rol'] === $rol &&
                    $anterior['nombre'] === $registro['nombre'] &&
                    $anterior['apellido'] === $registro['apellido'] &&
                    $anterior['username'] === $registro['username'] &&
                    $anterior['password'] === $registro['password'];

                if (!$mismoUsuario || $rol === 'alumno') {

                    $agregarError(
                        $hoja,
                        $fila,
                        'El RUT está repetido o contiene datos diferentes.'
                    );
                }
            }

            if (
                isset($nombresUsuario[$username]) &&
                $nombresUsuario[$username] !== $rut
            ) {

                $agregarError(
                    $hoja,
                    $fila,
                    'El nombre de usuario está duplicado en el Excel.'
                );
            }

            /* Asignar estudiantes a su curso */

            if ($rol === 'alumno') {

                $curso = normalizarTextoRegistro(
                    $registro['curso']
                );

                if (empty($cursos[$curso])) {

                    $agregarError(
                        $hoja,
                        $fila,
                        'El curso no existe o está inactivo.'
                    );

                } else {

                    foreach ($cursos[$curso] as $aulaId) {

                        $asignaciones[$rut][$aulaId] = true;
                    }
                }
            }

            /* Asignar profesores a sus asignaturas */

            if ($rol === 'profesor') {

                $curso = normalizarTextoRegistro(
                    $registro['curso']
                );

                $asignatura = normalizarTextoRegistro(
                    $registro['asignatura']
                );

                $coincidencias =
                    $asignaturas[$curso][$asignatura] ?? [];

                if (count($coincidencias) !== 1) {

                    $agregarError(
                        $hoja,
                        $fila,
                        'La combinación de curso y asignatura no existe o es ambigua.'
                    );

                } else {

                    $aulaId = $coincidencias[0];

                    $asignaciones[$rut][$aulaId] = true;
                }
            }

            /* Vincular apoderados */

            if ($rol === 'apoderado') {

                $rutEstudiante =
                    $registro['rut_estudiante'];

                if (
                    !validarRutRegistro($rutEstudiante) ||
                    !isset(
                        $estudiantesDisponibles[$rutEstudiante]
                    )
                ) {

                    $agregarError(
                        $hoja,
                        $fila,
                        'El estudiante asociado no existe o no es válido.'
                    );

                } else {

                    $vinculaciones[$rut][$rutEstudiante] =
                        true;
                }
            }

            /* Incorporar registros válidos */

            if (count($errores) === $erroresAntes) {

                $usuarios[$rut] = $registro;

                $nombresUsuario[$username] = $rut;

                if ($rol === 'alumno') {

                    $estudiantesDisponibles[$rut] = true;
                }
            }
        }
    }

    /* Contabilizar usuarios */

    $resumen = [
        'estudiantes' => 0,
        'profesores' => 0,
        'apoderados' => 0,
        'usuarios' => count($usuarios),
        'asignaciones' => 0,
        'vinculaciones' => 0
    ];

    foreach ($usuarios as $usuario) {

        $clave = match ($usuario['rol']) {
            'alumno' => 'estudiantes',
            'profesor' => 'profesores',
            'apoderado' => 'apoderados'
        };

        $resumen[$clave]++;
    }

    foreach ($asignaciones as $lista) {

        $resumen['asignaciones'] += count($lista);
    }

    foreach ($vinculaciones as $lista) {

        $resumen['vinculaciones'] += count($lista);
    }

    if (!$usuarios && !$errores) {

        $agregarError(
            'Excel',
            0,
            'El archivo no contiene usuarios para importar.'
        );
    }

    return [
        'valido' => count($errores) === 0,
        'errores' => $errores,
        'usuarios' => $usuarios,
        'asignaciones' => $asignaciones,
        'vinculaciones' => $vinculaciones,
        'resumen' => $resumen
    ];
}

/* Importación definitiva de usuarios */

function importarUsuariosRegistro(
    PDO $pdo,
    int $colegioId,
    int $adminId,
    array $datos
): array {

    if (
        empty($datos['valido']) ||
        empty($datos['usuarios']) ||
        !empty($datos['errores'])
    ) {
        throw new InvalidArgumentException(
            'No existe una importación válida.'
        );
    }

    $pdo->beginTransaction();

    try {

        /* Verificar administrador */

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE id = ?
              AND colegio_id = ?
              AND rol = 'admin'
              AND activo = TRUE
            FOR SHARE
        ");

        $stmt->execute([
            $adminId,
            $colegioId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException(
                'No tienes autorización para importar usuarios.'
            );
        }

        /* Evitar importaciones concurrentes */

        $pdo->exec("
            LOCK TABLE usuarios
            IN SHARE ROW EXCLUSIVE MODE
        ");

        /* Comprobar nuevamente usuarios existentes */

        $existentesRut = [];
        $existentesUsername = [];

        $stmt = $pdo->query("
            SELECT rut, username
            FROM usuarios
        ");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $usuario) {

            $existentesRut[
                normalizarRutRegistro($usuario['rut'])
            ] = true;

            $existentesUsername[
                mb_strtolower($usuario['username'])
            ] = true;
        }

        $idsUsuarios = [];

        $resumen = [
            'usuarios' => 0,
            'estudiantes' => 0,
            'profesores' => 0,
            'apoderados' => 0,
            'asignaciones' => 0,
            'vinculaciones' => 0
        ];

        /* Preparar creación de cuentas */

        $insertarUsuario = $pdo->prepare("
            INSERT INTO usuarios (
                colegio_id,
                rut,
                username,
                nombre,
                apellido,
                password_hash,
                rol,
                activo
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)
            RETURNING id
        ");

        foreach ($datos['usuarios'] as $registro) {

            $rut = normalizarRutRegistro(
                $registro['rut']
            );

            $username = mb_strtolower(
                $registro['username']
            );

            $rol = $registro['rol'];

            if (
                !validarRutRegistro($rut) ||
                !in_array(
                    $rol,
                    ['alumno', 'profesor', 'apoderado'],
                    true
                ) ||
                empty($registro['password_hash']) ||
                empty(
                    password_get_info(
                        $registro['password_hash']
                    )['algo']
                )
            ) {
                throw new InvalidArgumentException(
                    'Se encontraron datos de usuario no válidos.'
                );
            }

            if (
                isset($existentesRut[$rut]) ||
                isset($existentesUsername[$username])
            ) {
                throw new InvalidArgumentException(
                    'Un usuario ya existe. Vuelve a validar el Excel.'
                );
            }

            /* Guardar RUT con guion */

            $rutGuardado =
                substr($rut, 0, -1) .
                '-' .
                substr($rut, -1);

            $insertarUsuario->execute([
                $colegioId,
                $rutGuardado,
                $registro['username'],
                $registro['nombre'],
                $registro['apellido'],
                $registro['password_hash'],
                $rol
            ]);

            $usuarioId = (int)
                $insertarUsuario->fetchColumn();

            $idsUsuarios[$rut] = [
                'id' => $usuarioId,
                'rol' => $rol
            ];

            $existentesRut[$rut] = true;
            $existentesUsername[$username] = true;

            $resumen['usuarios']++;

            $clave = match ($rol) {
                'alumno' => 'estudiantes',
                'profesor' => 'profesores',
                'apoderado' => 'apoderados'
            };

            $resumen[$clave]++;
        }

        /* Obtener aulas activas y bloquear cambios */

        $pdo->exec("
    LOCK TABLE aulas IN SHARE MODE
");

$stmt = $pdo->prepare("
    SELECT id, nombre, asignatura_nombre
    FROM aulas
    WHERE colegio_id = ?
      AND activo = TRUE
    ORDER BY id
");

$stmt->execute([$colegioId]);

$aulasActuales = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (
    !isset($datos['aulas_snapshot']) ||
    $aulasActuales !== $datos['aulas_snapshot']
) {
    throw new InvalidArgumentException(
        'Las aulas han cambiado. Vuelve a validar el Excel.'
    );
}
        $stmt = $pdo->prepare("
            SELECT id, nombre, asignatura_nombre
            FROM aulas
            WHERE colegio_id = ?
              AND activo = TRUE
            ORDER BY id
            FOR SHARE
        ");

        $stmt->execute([$colegioId]);

        $aulas = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $aula) {

            $aulas[(int) $aula['id']] = $aula;
        }

        /* Preparar asignaciones */

        $insertarAlumno = $pdo->prepare("
            INSERT INTO aula_alumnos (
                aula_id,
                alumno_id
            )
            VALUES (?, ?)
        ");

        $insertarProfesor = $pdo->prepare("
            INSERT INTO aula_profesores (
                aula_id,
                profesor_id
            )
            VALUES (?, ?)
        ");

        /* Asignar estudiantes y profesores */

        foreach (
            $datos['asignaciones'] as $rut => $lista
        ) {

            if (!isset($idsUsuarios[$rut])) {
                throw new InvalidArgumentException(
                    'No se encontró un usuario para asignar.'
                );
            }

            $usuario = $idsUsuarios[$rut];

            foreach ($lista as $aulaId => $valor) {

                $aulaId = (int) $aulaId;

                if (!isset($aulas[$aulaId])) {
                    throw new InvalidArgumentException(
                        'Un aula ya no está disponible.'
                    );
                }

                if ($usuario['rol'] === 'alumno') {

                    $insertarAlumno->execute([
                        $aulaId,
                        $usuario['id']
                    ]);

                } elseif ($usuario['rol'] === 'profesor') {

                    $insertarProfesor->execute([
                        $aulaId,
                        $usuario['id']
                    ]);

                } else {

                    throw new InvalidArgumentException(
                        'Asignación de aula no válida.'
                    );
                }

                $resumen['asignaciones']++;
            }
        }

        /* Obtener estudiantes activos del colegio */

        $stmt = $pdo->prepare("
            SELECT id, rut
            FROM usuarios
            WHERE colegio_id = ?
              AND rol = 'alumno'
              AND activo = TRUE
            FOR SHARE
        ");

        $stmt->execute([$colegioId]);

        $estudiantes = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $alumno) {

            $estudiantes[
                normalizarRutRegistro($alumno['rut'])
            ] = (int) $alumno['id'];
        }

        /* Preparar vinculaciones familiares */

        $insertarVinculo = $pdo->prepare("
            INSERT INTO apoderado_estudiantes (
                colegio_id,
                apoderado_id,
                estudiante_id,
                estado,
                confirmada_en,
                confirmada_por
            )
            VALUES (
                ?, ?, ?,
                'confirmada',
                CURRENT_TIMESTAMP,
                ?
            )
        ");

        foreach (
            $datos['vinculaciones'] as $rutApoderado => $lista
        ) {

            $apoderado =
                $idsUsuarios[$rutApoderado] ?? null;

            if (
                !$apoderado ||
                $apoderado['rol'] !== 'apoderado'
            ) {
                throw new InvalidArgumentException(
                    'El apoderado no está disponible.'
                );
            }

            foreach ($lista as $rutEstudiante => $valor) {

                if (!isset($estudiantes[$rutEstudiante])) {
                    throw new InvalidArgumentException(
                        'El estudiante asociado no está disponible.'
                    );
                }

                $insertarVinculo->execute([
                    $colegioId,
                    $apoderado['id'],
                    $estudiantes[$rutEstudiante],
                    $adminId
                ]);

                $resumen['vinculaciones']++;
            }
        }

        /* Confirmar todas las operaciones */

        $pdo->commit();

        return $resumen;

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
