<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/apoderados.php';

final class ApoderadosConsultasTest extends TestCase
{
    private PDO $pdo;
    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec("CREATE TABLE usuarios(id integer PRIMARY KEY,colegio_id integer,nombre text,apellido text,rol text,activo boolean);
            CREATE TABLE aulas(id integer PRIMARY KEY,colegio_id integer,nombre text,asignatura_nombre text,activo boolean);
            CREATE TABLE aula_alumnos(aula_id integer,alumno_id integer);
            CREATE TABLE evaluaciones(id integer PRIMARY KEY,aula_id integer,titulo text,publicada_en text);
            CREATE TABLE entregas(id integer PRIMARY KEY,evaluacion_id integer,estudiante_id integer,estado text,calificacion numeric,fecha_entrega text);
            CREATE TABLE apoderado_estudiantes(colegio_id integer,apoderado_id integer,estudiante_id integer,estado text,confirmada_en text,confirmada_por integer,PRIMARY KEY(colegio_id,apoderado_id,estudiante_id));
            INSERT INTO usuarios VALUES (1,1,'Admin','Uno','admin',1),(2,1,'Apoderado','Uno','apoderado',1),(3,1,'Alumno','Uno','alumno',1),(4,2,'Alumno','Ajeno','alumno',1),(5,1,'Alumno','Dos','alumno',1);
            INSERT INTO aulas VALUES (10,1,'Matematicas','Matematicas',1),(11,1,'Sin configurar',NULL,1),(12,2,'Ajena','Ciencias',1);
            INSERT INTO aula_alumnos VALUES (10,3),(11,3),(12,4),(10,5);");
    }
    public function testConfirmacionSeleccionYDenegacion(): void
    {
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        self::assertFalse(confirmarVinculoApoderado($this->pdo,1,1,2,2));
        self::assertFalse(confirmarVinculoApoderado($this->pdo,1,1,2,4));
        self::assertFalse(confirmarVinculoApoderado($this->pdo,2,1,2,3));
        self::assertTrue(confirmarVinculoApoderado($this->pdo,1,1,2,3));
        self::assertFalse(confirmarVinculoApoderado($this->pdo,1,1,2,3));
        self::assertCount(1,pupilosAutorizados($this->pdo,2,1));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,4));
        self::assertSame([],rendimientoApoderado($this->pdo,2,2,3));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,5));
        $this->pdo->exec("UPDATE apoderado_estudiantes SET estado='pendiente', confirmada_en=NULL, confirmada_por=NULL");
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,3));
        self::assertTrue(confirmarVinculoApoderado($this->pdo,1,1,2,3));
    }
    public function testPromedioNoLimitadoACinco(): void
    {
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        foreach ([1,5,6,6,6,6] as $i=>$nota) {
            $id=$i+1;
            consultaApoderados($this->pdo,"INSERT INTO evaluaciones VALUES (?,10,?,'2026-01-01')",[$id,'Prueba '.$id]);
            consultaApoderados($this->pdo,"INSERT INTO entregas VALUES (?,?,3,'calificada',?,'2026-01-01')",[$id,$id,$nota]);
        }
        $this->pdo->exec("INSERT INTO evaluaciones VALUES (7,10,'Borrador',NULL),(8,10,'Pendiente','2026-01-01');
            INSERT INTO entregas VALUES (7,7,3,'calificada',7,'2026-01-01'),(8,8,3,'entregada',NULL,'2026-01-01');");
        $aulas=rendimientoApoderado($this->pdo,2,1,3);
        self::assertEquals(5.0,$aulas[10]['promedio']);
        self::assertSame(['Prueba 6','Prueba 5','Prueba 4','Prueba 3','Prueba 2'],array_column($aulas[10]['evaluaciones'],'titulo'));
        self::assertNull($aulas[11]['promedio']); self::assertSame([],$aulas[11]['evaluaciones']);
        self::assertSame('Asignatura sin configurar',$aulas[11]['asignatura']);
    }
    public function testEstadoActualDeCuentaYMatricula(): void
    {
        confirmarVinculoApoderado($this->pdo,1,1,2,3);
        $this->pdo->exec('DELETE FROM aula_alumnos WHERE alumno_id=3');
        self::assertSame([],rendimientoApoderado($this->pdo,2,1,3));
        $this->pdo->exec('UPDATE usuarios SET activo=0 WHERE id=3');
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
        $this->pdo->exec("UPDATE usuarios SET activo=1,rol='profesor' WHERE id=3");
        self::assertSame([],pupilosAutorizados($this->pdo,2,1));
    }
}
