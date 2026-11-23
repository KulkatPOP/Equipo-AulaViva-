<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ActivarCuentaCoberturaTest extends TestCase
{
    private function prepararBaseDeDatos(): PDO
    {
        $pdo = new PDO('sqlite::memory:');

        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            PDO::ERRMODE_EXCEPTION
        );

        $pdo->exec(
            'CREATE TABLE invitaciones (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER,
                email TEXT,
                rut TEXT,
                username TEXT,
                rol TEXT,
                expira_en TEXT,
                usado INTEGER,
                token_hash TEXT
            )'
        );

        $pdo->exec(
            'CREATE TABLE usuarios (
                id INTEGER PRIMARY KEY,
                colegio_id INTEGER NOT NULL,
                rut TEXT UNIQUE,
                username TEXT UNIQUE,
                nombre TEXT,
                apellido TEXT,
                password_hash TEXT,
                rol TEXT,
                activo INTEGER NOT NULL DEFAULT 1
            )'
        );

        $pdo->exec("INSERT INTO usuarios (id,colegio_id,rut,username,nombre,apellido,password_hash,rol,activo) VALUES (10,1,'docente-test','docente-test','Docente','Prueba','fixture','profesor',1)");

        $stmt = $pdo->prepare(
            'INSERT INTO invitaciones
             (id, colegio_id, email, rut, username,
              rol, expira_en, usado, token_hash)
             VALUES
             (1, 1, :email, :rut, :username,
              :rol, :expira_en, 0, :token_hash)'
        );

        $stmt->execute([
            ':email' => 'alumno@ejemplo.cl',
            ':rut' => '12345678-9',
            ':username' => 'alumno1',
            ':rol' => 'alumno',
            ':expira_en' => date('Y-m-d H:i:s', time() + 86400),
            ':token_hash' => hash('sha256', str_repeat('a', 64))
        ]);

        return $pdo;
    }

    private function ejecutarPagina(
        string $metodo,
        array $datos = []
    ): string {
        $_SERVER['REQUEST_METHOD'] = $metodo;
        $_GET['token'] = str_repeat('a', 64);
        if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
        $_SESSION['activacion_cuenta'] = ['hash' => hash('sha256', str_repeat('a', 64)), 'csrf' => 'csrf-prueba'];
        $_POST = $datos + ['csrf_token' => 'csrf-prueba'];

        $pdo = $this->prepararBaseDeDatos();

        ob_start();
        require __DIR__ . '/../activar_cuenta.php';

        return ob_get_clean();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMuestraFormularioDeActivacion(): void
    {
        $html = $this->ejecutarPagina('GET');

        $this->assertStringContainsString(
            'Crear cuenta',
            $html
        );

        $this->assertStringContainsString(
            'alumno@ejemplo.cl',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRechazaCamposVacios(): void
    {
        $html = $this->ejecutarPagina('POST', [
            'nombre' => '',
            'apellido' => '',
            'username' => '',
            'password' => '',
            'confirmar_password' => ''
        ]);

        $this->assertStringContainsString(
            'Debes completar todos los campos.',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRechazaContrasenasDiferentes(): void
    {
        $html = $this->ejecutarPagina('POST', [
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'username' => 'juan1',
            'password' => 'Password123',
            'confirmar_password' => 'OtraClave123'
        ]);

        $this->assertStringContainsString(
            'no coinciden',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRechazaContrasenaCorta(): void
    {
        $html = $this->ejecutarPagina('POST', [
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'username' => 'juan1',
            'password' => '123',
            'confirmar_password' => '123'
        ]);

        $this->assertStringContainsString(
            'al menos 8 caracteres',
            $html
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCreaUsuarioYConsumeInvitacionConDatosValidos(): void
    {
        require_once __DIR__ . '/../config/activacion.php';
        $pdo = $this->prepararBaseDeDatos();
        $id = activarInvitacion($pdo, hash('sha256', str_repeat('a', 64)), [
            'nombre' => 'Juan', 'apellido' => 'Pérez',
            'username' => 'no-confiar', 'colegio_id' => 999, 'rol' => 'admin',
            'password' => 'Password123', 'confirmar_password' => 'Password123'
        ]);
        $usuario = $pdo->query('SELECT * FROM usuarios WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
        self::assertSame('alumno1', $usuario['username']);
        self::assertSame('alumno', $usuario['rol']);
        self::assertSame(1, (int) $usuario['colegio_id']);
        self::assertTrue(password_verify('Password123', $usuario['password_hash']));
        self::assertNotSame('Password123', $usuario['password_hash']);
        self::assertSame(1, (int) $pdo->query('SELECT usado FROM invitaciones WHERE id=1')->fetchColumn());
    }
}
