<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../config/apoderados.php';
require_once __DIR__ . '/../config/funciones_rol.php';

final class ApoderadosValidacionTest extends TestCase
{
    public function testIdsRechazanArraysNegativosEInyeccion(): void
    {
        foreach ([null,[],true,false,0,-1,'0','-1','1.5','1 OR 1=1','2147483648'] as $valor) {
            self::assertNull(idApoderados($valor));
        }
        self::assertSame(1,idApoderados('1'));
        self::assertSame(2147483647,idApoderados('2147483647'));
    }
    public function testRolesConservanAutorizacionExacta(): void
    {
        foreach (['admin','alumno','profesor','apoderado'] as $requerido) {
            foreach (['admin','alumno','profesor','apoderado'] as $actual) {
                self::assertSame($requerido === $actual ? null : 403,verificarPermisoRol($requerido,$actual));
            }
        }
        self::assertSame(500,verificarPermisoRol('desconocido','admin'));
    }
    public function testSalidaEscapaContenido(): void
    {
        self::assertSame('&lt;script&gt;&quot;&#039;&amp;',escaparApoderados('<script>"\'&'));
        self::assertSame('Sin fecha', formatearFechaApoderados(null));
        self::assertSame('Sin fecha', formatearFechaApoderados(''));
        self::assertSame('Sin fecha', formatearFechaApoderados('fecha no válida'));
        self::assertSame('7 de noviembre de 2026, 00:30', formatearFechaApoderados('2026-11-07 00:30:00+00'));
        self::assertSame('6 de noviembre de 2026, 21:30', formatearFechaApoderados('2026-11-06 21:30:00-03'));

    }
}
