<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/apoderados.php';
require_once __DIR__ . '/../config/funciones_rol.php';

final class ApoderadosPostgresTest extends TestCase
{
    private PDO $pdo;
    private string $schema;
    private $server = null;
    private ?string $cookie = null;
    private int $port;

    protected function setUp(): void
    {
        $dsn = getenv('US05_TEST_DSN');
        if (!$dsn) { $this->markTestSkipped('Requiere PostgreSQL desechable US05_TEST_DSN y base us05_test.'); }
        $this->pdo = new PDO($dsn, getenv('US05_TEST_USER') ?: 'postgres', getenv('US05_TEST_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($this->pdo->query('SELECT current_database()')->fetchColumn() !== 'us05_test') {
            throw new RuntimeException('Las pruebas requieren la base desechable us05_test.');
        }
        $this->schema = 'us05_' . bin2hex(random_bytes(6));
        $this->pdo->exec('CREATE SCHEMA ' . $this->schema);
        $this->pdo->exec('SET search_path TO ' . $this->schema);
        $this->pdo->exec("CREATE TABLE usuarios (id integer PRIMARY KEY, colegio_id integer NOT NULL, nombre text NOT NULL, apellido text NOT NULL, username text, rol text CHECK (rol IN ('alumno','profesor','admin')), activo boolean NOT NULL DEFAULT TRUE);
            CREATE TABLE aulas (id integer PRIMARY KEY, colegio_id integer NOT NULL, nombre text NOT NULL, activo boolean NOT NULL DEFAULT TRUE);
            CREATE TABLE aula_alumnos (aula_id integer REFERENCES aulas(id), alumno_id integer REFERENCES usuarios(id), PRIMARY KEY(aula_id, alumno_id));");
        foreach (['002_evaluaciones.sql', '003_rendicion.sql', '005_apoderados.sql'] as $file) {
            $this->pdo->exec(file_get_contents(__DIR__ . '/../migrations/' . $file));
        }
        $this->pdo->exec("INSERT INTO usuarios(id,colegio_id,nombre,apellido,rol) VALUES
            (1,1,'Admin','Uno','admin'),(2,1,'Apoderado','Uno','apoderado'),(3,1,'Alumno','Uno','alumno'),
            (4,1,'Alumno','Dos','alumno'),(5,2,'Alumno','Ajeno','alumno'),(6,2,'Admin','Ajeno','admin'),
            (7,1,'Profesor','Uno','profesor'),(8,1,'Apoderado','Dos','apoderado');
            INSERT INTO aulas VALUES (10,1,'Matemáticas',TRUE,'Matemáticas'),(11,1,'Lenguaje',TRUE,NULL),(12,2,'Ajena',TRUE,'Ciencias');
            INSERT INTO aula_alumnos VALUES (10,3),(11,3),(10,4),(12,5);");
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) { proc_terminate($this->server); proc_close($this->server); }
        if ($this->cookie !== null) { @unlink($this->cookie); }
        if (isset($this->pdo, $this->schema)) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            $this->pdo->exec('SET search_path TO public');
            $this->pdo->exec('DROP SCHEMA ' . $this->schema . ' CASCADE');
        }
    }

    private function nota(int $numero, string $valor, bool $publicada = true, string $estado = 'calificada', int $aula = 10): void
    {
        $id = sprintf('00000000-0000-4000-8000-%012d', $numero);
        consultaApoderados($this->pdo, 'INSERT INTO evaluaciones(id,aula_id,titulo,publicada_en) VALUES (?,?,?,?)', [$id,$aula,'Evaluación ' . $numero,$publicada ? '2026-01-01' : null]);
        consultaApoderados($this->pdo, "INSERT INTO entregas(id,evaluacion_id,estudiante_id,fecha_inicio,fecha_vencimiento,fecha_entrega,puntaje_obtenido,puntaje_maximo,calificacion,estado)
            VALUES (?,?,3,CAST(? AS timestamptz),CAST(? AS timestamptz) + interval '60 minutes',?, ?, ?, ?, ?)",
            [$id,$id,'2026-01-01','2026-01-01',$estado === 'en_progreso' ? null : '2026-01-01 00:30:00+00', $estado === 'calificada' ? 5 : null,$estado === 'calificada' ? 10 : null,$estado === 'calificada' ? $valor : null,$estado]);
    }

    public function testPromedioTodasLasNotasPeroSoloCincoUltimas(): void
    {
        self::assertTrue(confirmarVinculoApoderado($this->pdo,1,1,2,3));
        foreach (['1.0','5.0','6.0','6.0','6.0','6.0'] as $i => $nota) { $this->nota($i+1,$nota); }
        $this->nota(7,'7.0',false);
        $this->nota(8,'7.0',true,'entregada');
        $this->nota(9,'7.0',true,'en_progreso');
        $aulas = rendimientoApoderado($this->pdo,2,1,3);
        self::assertEquals('5.0',$aulas[10]['promedio']);
        self::assertCount(5,$aulas[10]['evaluaciones']);
        self::assertSame(['Evaluación 6','Evaluación 5','Evaluación 4','Evaluación 3','Evaluación 2'], array_column($aulas[10]['evaluaciones'],'titulo'));
        self::assertNull($aulas[11]['promedio']);
        self::assertSame('Asignatura sin configurar',$aulas[11]['asignatura']);
    }

    public function testRedondeoYSeparacionPorAula(): void
    {
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        $this->nota(1,'5.0'); $this->nota(2,'6.0'); $this->nota(3,'6.5'); $this->nota(4,'2.0',true,'calificada',11);
        $aulas = rendimientoApoderado($this->pdo,2,1,3);
        self::assertEquals('5.8',$aulas[10]['promedio']); self::assertEquals('2.0',$aulas[11]['promedio']);
    }

    public function testPendienteNoDaAccesoYAdminConfirma(): void
    {
        $this->pdo->exec("INSERT INTO apoderado_estudiantes(colegio_id,apoderado_id,estudiante_id) VALUES (1,2,3)");
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,3));
        self::assertTrue(confirmarVinculoApoderado($this->pdo,1,1,2,3));
        self::assertCount(1,pupilosAutorizados($this->pdo,2,1));
        self::assertFalse(confirmarVinculoApoderado($this->pdo,1,1,2,3));
    }

    public function testAislamientoYManipulacionDeIds(): void
    {
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,4));
        self::assertSame([],rendimientoApoderado($this->pdo,2,2,3));
        self::assertSame([],rendimientoApoderado($this->pdo,8,1,3));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,5));
        foreach ([[1,1,2,5],[6,1,2,3],[7,1,2,3],[1,1,2,2],[1,1,7,3],[1,1,2,7]] as $args) {
            self::assertFalse(confirmarVinculoApoderado($this->pdo,...$args));
        }
    }

    public function testAlumnoInactivoORolCambiadoNoDaAcceso(): void
    {
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        $this->pdo->exec('UPDATE usuarios SET activo = FALSE WHERE id=3');
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,3));
        $this->pdo->exec("UPDATE usuarios SET activo=TRUE, rol='profesor' WHERE id=3");
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
    }

    public function testVariosPupilosYSinVinculos(): void
    {
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        confirmarVinculoApoderado($this->pdo,1,1,2,4);
        self::assertCount(2,pupilosAutorizados($this->pdo,2,1));
        self::assertSame([],pupilosAutorizados($this->pdo,2,2));
        $this->pdo->exec('UPDATE usuarios SET activo=FALSE WHERE id=2');
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,3));
    }

    public function testFkImpideVinculoEntreColegios(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO apoderado_estudiantes(colegio_id,apoderado_id,estudiante_id) VALUES (1,2,5)");
    }

    public function testTriggerImpideConfirmacionPorProfesor(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO apoderado_estudiantes VALUES (1,2,3,'confirmada',CURRENT_TIMESTAMP,7)");
    }

    private function demarrerHttp(): void
    {
        $this->pdo->exec('ALTER TABLE usuarios ADD COLUMN rut text, ADD COLUMN password_hash text');
        $hash = password_hash('us05-test-only', PASSWORD_DEFAULT);
        consultaApoderados($this->pdo, "UPDATE usuarios SET rut = 'test-' || id::text, username = 'test-' || id::text, password_hash = ?", [$hash]);
        $this->port = random_int(20000,45000);
        $env = getenv();
        $env['DB_DSN'] = getenv('US05_TEST_DSN');
        $env['DB_USER'] = getenv('US05_TEST_USER') ?: 'postgres';
        $env['DB_PASSWORD'] = getenv('US05_TEST_PASSWORD') ?: '';
        $env['PGOPTIONS'] = '-c search_path=' . $this->schema;
        $this->server = proc_open([PHP_BINARY,'-S','127.0.0.1:' . $this->port,'-t',dirname(__DIR__)],
            [0 => ['pipe','r'],1 => ['file','/dev/null','a'],2 => ['file','/dev/null','a']],$pipes,null,$env);
        if (!is_resource($this->server)) { throw new RuntimeException('Servidor de prueba no disponible'); }
        fclose($pipes[0]);
        $this->cookie = tempnam(sys_get_temp_dir(),'us05-cookie-');
        for ($i=0;$i<40;$i++) {
            $socket = @fsockopen('127.0.0.1',$this->port,$errno,$message,.1);
            if ($socket) { fclose($socket); return; }
            usleep(50000);
        }
        throw new RuntimeException('Servidor de prueba no disponible');
    }

    private function http(string $path, ?array $post = null): array
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . '/' . $path);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_TIMEOUT=>5]);
        if ($post !== null) { curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]); }
        $body = curl_exec($curl); $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status,$body];
    }

    private function loginHttp(int $usuario): void
    {
        [$status,$body]=$this->http('login.php'); self::assertSame(200,$status);
        preg_match('/name="csrf_token" value="([^"]+)"/',$body,$match);
        [$status]=$this->http('login.php',['identificador'=>'test-'.$usuario,'password'=>'us05-test-only','csrf_token'=>$match[1] ?? '']);
        self::assertSame(302,$status);
    }

    public function testHttpPanelSelectorSinVinculosYAutorizacion(): void
    {
        $this->demarrerHttp();
        self::assertSame(302,$this->http('panel_apoderado.php')[0]);
        $this->loginHttp(2);
        [$status,$body]=$this->http('panel_apoderado.php');
        self::assertSame(200,$status);
        self::assertStringContainsString('No hay estudiantes asociados a tu cuenta. Contacte a Coordinación',$body);
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        confirmarVinculoApoderado($this->pdo,1,1,2,4);
        [$status,$body]=$this->http('panel_apoderado.php?estudiante_id=3');
        self::assertSame(200,$status);
        self::assertStringContainsString('Sin calificaciones',$body);
        self::assertStringContainsString('name="estudiante_id"',$body);
        self::assertSame(200,$this->http('panel_apoderado.php?estudiante_id=4')[0]);
        [$status,$body] = $this->http('panel_apoderado.php?estudiante_id=5');
        self::assertSame(403,$status);
        self::assertStringContainsString('Estudiante no disponible.',$body);
        self::assertStringContainsString('Volver al panel del apoderado',$body);
        self::assertStringContainsString('<header>',$body);
        self::assertStringNotContainsString('Promedio actual:',$body);
        $this->nota(1,'5.0');
        [$status,$body] = $this->http('panel_apoderado.php?estudiante_id=3');
        self::assertSame(200,$status);
        self::assertStringContainsString('1 de enero de 2026, 00:30',$body);
        self::assertStringNotContainsString('00:30:00+00',$body);
        self::assertSame(403,$this->http('panel_apoderado.php?estudiante_id[]=3')[0]);
        self::assertSame(403,$this->http('vinculos_apoderado.php')[0]);
        $this->http('logout.php');
        $this->loginHttp(7);
        self::assertSame(403,$this->http('panel_apoderado.php')[0]);
    }

    public function testHttpAdminCsrfConfirmacion(): void
    {
        $this->pdo->exec("INSERT INTO apoderado_estudiantes(colegio_id,apoderado_id,estudiante_id) VALUES (1,2,3);
            INSERT INTO usuarios(id,colegio_id,nombre,apellido,rol) VALUES (9,2,'Apoderado','Ajeno','apoderado');
            INSERT INTO apoderado_estudiantes(colegio_id,apoderado_id,estudiante_id) VALUES (2,9,5);");
        $this->demarrerHttp(); $this->loginHttp(1);
        [$status,$body]=$this->http('vinculos_apoderado.php'); self::assertSame(200,$status);
        self::assertStringContainsString('Vinculaciones existentes',$body);
        self::assertStringContainsString('Pendiente',$body);
        self::assertStringContainsString('aria-label="Confirmar vínculo de Alumno Uno con Apoderado Uno"',$body);
        self::assertStringNotContainsString('Apoderado Ajeno',$body);

        preg_match('/name="csrf_token" value="([^"]+)"/',$body,$match);
        self::assertSame(403,$this->http('vinculos_apoderado.php',['apoderado_id'=>2,'estudiante_id'=>3,'csrf_token'=>'incorrecto'])[0]);
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        self::assertSame(303,$this->http('vinculos_apoderado.php',['apoderado_id'=>2,'estudiante_id'=>3,'csrf_token'=>$match[1]])[0]);
        self::assertCount(1,pupilosAutorizados($this->pdo,2,1));
        [$status,$body]=$this->http('vinculos_apoderado.php');
        self::assertStringContainsString('Vinculación confirmada.',$body);
        self::assertStringContainsString('Confirmada',$body);
        self::assertStringNotContainsString('aria-label="Confirmar vínculo',$body);
        self::assertStringNotContainsString('Apoderado Ajeno',$body);
        self::assertStringNotContainsString('Vinculación confirmada.',$this->http('vinculos_apoderado.php')[1]);
    }

    public function testRolesEIds(): void
    {
        foreach (['alumno','profesor','admin','apoderado'] as $rol) {
            self::assertNull(verificarPermisoRol($rol,$rol));
        }
        self::assertSame(403,verificarPermisoRol('apoderado','alumno'));
        foreach ([0,-1,'1 OR 1=1',[],null,'1.5'] as $valor) { self::assertNull(idApoderados($valor)); }
        self::assertSame(3,idApoderados('3'));
    }
}
