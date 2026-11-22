# US05 - Panel del apoderado

Aplicar una sola vez con `psql -X -v ON_ERROR_STOP=1 -f migrations/005_apoderados.sql`, despues de 002 y 003 y previa copia de seguridad. Migracion transaccional, no idempotente: una segunda ejecucion falla y revierte. No se aplica automaticamente al arrancar.

Una asignatura por aula operativa: `aulas.asignatura_nombre`. Los valores existentes permanecen NULL hasta configurarlos y muestran "Asignatura sin configurar".

Aprovisionar cuentas administrativamente con `password_hash(..., PASSWORD_DEFAULT)`, sin guardar contrasenas en Git. No se incorpora alta de cuentas ni invitaciones. Login admin abre `vinculos_apoderado.php`: seleccionar apoderado y alumno activos del colegio, verificar la asociacion y confirmar. El formulario crea una relacion confirmada o confirma una pendiente, con CSRF y registro de administrador y fecha. No implementa revocacion ni verificacion documental.

Login apoderado abre `panel_apoderado.php`. GET se valida contra vinculos confirmados y la consulta academica repite la autorizacion. Solo aulas activas con matricula actual. No muestra historial de aulas sin matricula actual.

Promedio simple de todas las notas calificadas y publicadas por aula, redondeado a una decima en PostgreSQL. Sin ponderaciones ni periodo. Cinco ultimas por fecha de entrega e ID descendentes; no es fecha de calificacion.

Pruebas: `US05_TEST_DSN` debe apuntar a una base desechable llamada `us05_test`; opcionalmente configurar `US05_TEST_USER` y `US05_TEST_PASSWORD`. PHPUnit crea y elimina un esquema propio por caso y aplica 002, 003 y 005. Sin DSN, estos casos se omiten. Nunca usar DEV.

Validacion manual: login apoderado, selector, notas, promedio, sin notas, sin vinculos, manipulacion de estudiante; login admin, confirmacion y CSRF invalido; teclado y 375 px. No necesita servicios ni cambios en GitOps.
