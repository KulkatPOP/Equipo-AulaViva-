<?php
declare(strict_types=1);

final class ErrorActivacion extends RuntimeException
{
    public int $estadoHttp;

    public function __construct(int $estadoHttp, string $mensaje)
    {
        parent::__construct($mensaje);
        $this->estadoHttp = $estadoHttp;
    }
}

function tokenActivacionValido(mixed $token): bool
{
    return is_string($token) && preg_match('/\A[0-9a-f]{64}\z/', $token) === 1;
}

function consultarInvitacionActivacion(PDO $pdo, string $hash, bool $bloquear = false): array
{
    $sql = 'SELECT id, colegio_id, email, rut, username, rol, expira_en, usado
            FROM invitaciones WHERE token_hash = ?';
    if ($bloquear && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') { $sql .= ' FOR UPDATE'; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$hash]);
    $invitacion = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invitacion) { throw new ErrorActivacion(400, 'Invitación no válida.'); }
    if (in_array($invitacion['usado'], [true, 1, '1', 't'], true)) {
        throw new ErrorActivacion(410, 'Esta invitación ya fue utilizada.');
    }
    // Consultar el reloj de PostgreSQL DESPUÉS de adquirir el bloqueo.
    $reloj = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'clock_timestamp()' : 'CURRENT_TIMESTAMP';
    $stmt = $pdo->prepare('SELECT 1 FROM invitaciones WHERE id = ? AND expira_en > ' . $reloj);
    $stmt->execute([$invitacion['id']]);
    if ($stmt->fetchColumn() === false) { throw new ErrorActivacion(410, 'Esta invitación ha expirado.'); }
    $colegio = filter_var($invitacion['colegio_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    if ($colegio === false || !in_array($invitacion['rol'], ['alumno', 'profesor'], true)
        || !is_string($invitacion['rut']) || trim($invitacion['rut']) === ''
        || !is_string($invitacion['username']) || trim($invitacion['username']) === '') {
        throw new ErrorActivacion(400, 'Invitación no válida.');
    }
    // No existe un catálogo colegios en el modelo. Exigir una institución
    // respaldada por un docente/admin activo, como el emisor de la API actual.
    $sql = "SELECT id FROM usuarios WHERE colegio_id = ? AND activo = TRUE AND rol IN ('profesor', 'admin') ORDER BY id LIMIT 1";
    if ($bloquear && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') { $sql .= ' FOR SHARE'; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$colegio]);
    if ($stmt->fetchColumn() === false) { throw new ErrorActivacion(400, 'Invitación no válida.'); }
    return $invitacion;
}

function validarFormularioActivacion(array $datos): array
{
    foreach (['nombre', 'apellido', 'password', 'confirmar_password'] as $campo) {
        if (!is_string($datos[$campo] ?? null) || trim($datos[$campo]) === '') {
            throw new ErrorActivacion(422, 'Debes completar todos los campos.');
        }
    }
    $nombre = trim($datos['nombre']);
    $apellido = trim($datos['apellido']);
    foreach ([$nombre, $apellido] as $texto) {
        if (str_contains($texto, "\0") || preg_match('/\A.{1,120}\z/us', $texto) !== 1) {
            throw new ErrorActivacion(422, 'Nombre y apellido deben tener hasta 120 caracteres válidos.');
        }
    }
    if ($datos['password'] !== $datos['confirmar_password']) {
        throw new ErrorActivacion(422, 'Las contraseñas no coinciden.');
    }
    if (strlen($datos['password']) < 8) {
        throw new ErrorActivacion(422, 'La contraseña debe tener al menos 8 caracteres.');
    }
    // PASSWORD_DEFAULT usa bcrypt actualmente: impedir truncación silenciosa.
    if (strlen($datos['password']) > 72 || str_contains($datos['password'], "\0")) {
        throw new ErrorActivacion(422, 'La contraseña debe tener como máximo 72 bytes y no contener caracteres nulos.');
    }
    return [$nombre, $apellido, $datos['password']];
}

function activarInvitacion(PDO $pdo, string $hash, array $datos): int
{
    [$nombre, $apellido, $password] = validarFormularioActivacion($datos);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $pdo->beginTransaction();
        $invitacion = consultarInvitacionActivacion($pdo, $hash, true);
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE rut = ? OR username = ? LIMIT 1');
        $stmt->execute([$invitacion['rut'], $invitacion['username']]);
        if ($stmt->fetchColumn() !== false) {
            throw new ErrorActivacion(409, 'No se pudo activar la cuenta con los datos de la invitación. Contacte al establecimiento.');
        }
        $stmt = $pdo->prepare('INSERT INTO usuarios (colegio_id, rut, username, nombre, apellido, password_hash, rol, activo)
                              VALUES (?, ?, ?, ?, ?, ?, ?, TRUE) RETURNING id');
        $stmt->execute([$invitacion['colegio_id'], $invitacion['rut'], $invitacion['username'], $nombre, $apellido, $passwordHash, $invitacion['rol']]);
        $usuario = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        $reloj = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'clock_timestamp()' : 'CURRENT_TIMESTAMP';
        $stmt = $pdo->prepare('UPDATE invitaciones SET usado = TRUE WHERE id = ? AND usado = FALSE AND expira_en > ' . $reloj);
        $stmt->execute([$invitacion['id']]);
        if ($stmt->rowCount() !== 1) { throw new ErrorActivacion(410, 'La invitación ya no está disponible.'); }
        $pdo->commit();
        return $usuario;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if ($error instanceof ErrorActivacion) { throw $error; }
        if ($error instanceof PDOException && in_array($error->getCode(), ['23505', '23000'], true)) {
            throw new ErrorActivacion(409, 'No se pudo activar la cuenta con los datos de la invitación. Contacte al establecimiento.');
        }
        // No propagar mensajes SQL, contraseñas o tokens al navegador ni logs.
        throw new ErrorActivacion(500, 'No se pudo activar la cuenta. Inténtalo nuevamente.');
    }
}
