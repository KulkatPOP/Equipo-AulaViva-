# Modelo de amenazas STRIDE — Invitaciones de AulaViva

## Alcance

Se analiza el flujo de emisión de invitaciones desde `POST /api/v1/invitaciones/` y la validación del enlace en `activar_cuenta.php`. También se incluye `crear_invitacion.php`, porque el generador de prueba escribe en la misma tabla de invitaciones.

## Activos y límites de confianza

- **Activos:** sesión del profesor, colegio asociado, correo y RUT del invitado, rol de la cuenta, token de activación y registros de invitaciones.
- **Navegador → aplicación:** la aplicación recibe la sesión, los datos de la solicitud y la clave de idempotencia. El colegio y el rol del profesor se obtienen de la sesión del servidor.
- **Aplicación → base de datos:** el código PHP consulta y registra las invitaciones con PDO. El aislamiento entre colegios depende de los filtros de las consultas y los permisos de la conexión.
- **Profesor → invitado:** el profesor recibe el enlace de activación y lo comparte. Quien obtiene ese enlace puede presentar el token mientras siga vigente.

## Análisis STRIDE

| Categoría | Amenaza en AulaViva | Control existente o aplicado en S09 | Mitigación y verificación pendientes |
| --- | --- | --- | --- |
| S — Suplantación | Una persona usa una sesión robada para emitir invitaciones o presenta un enlace que recibió por error. | La API exige una sesión de profesor. El token es aleatorio, se guarda como hash y vence en 24 horas. | Verificar HTTPS y los atributos `Secure`, `HttpOnly` y `SameSite` de la cookie de la API. Probar el rechazo de tokens vencidos. |
| T — Manipulación | Se intenta alterar una consulta SQL, cambiar el colegio o repetir una solicitud para crear registros duplicados. | La API obtiene el colegio desde la sesión, usa parámetros PDO y busca la clave de idempotencia por colegio. | Verificar en base de datos la unicidad de la clave por colegio y probar dos solicitudes simultáneas. |
| R — Repudio | Un profesor niega haber emitido una invitación y el equipo no puede atribuir la acción. | La respuesta indica si se reutilizó una solicitud previa. | Registrar el usuario emisor, el colegio, el ID de invitación y el resultado. Probar que cada emisión se pueda rastrear sin registrar el token. |
| I — Divulgación | El enlace con el token queda expuesto en el historial, los registros o la cabecera `Referer`. | La base de datos guarda el hash del token. La respuesta a un reintento no vuelve a incluir el enlace. | Evitar registrar el token en las URL de acceso y aplicar `Referrer-Policy: no-referrer` en la página de activación. |
| D — Denegación de servicio | Un usuario satura la API enviando muchas solicitudes de invitación. | La API valida la clave de idempotencia y rechaza duplicados activos. | Definir un límite por usuario y colegio. Probar que, al superarlo, la API responda `429`. |
| E — Elevación de privilegios | Un usuario intenta emitir invitaciones con un rol no autorizado o invocar el generador de prueba desde la web. | La API restringe la emisión a profesores y valida los roles admitidos. En S09, el generador de prueba devuelve `404` por HTTP. | Confirmar quién puede invitar a otros profesores y probar el rechazo de solicitudes hechas por alumnos o desde otro colegio. |

## Criterio de revisión

Revisar este modelo cuando cambien los roles, el aislamiento entre colegios o el flujo de activación. Mantener las mitigaciones pendientes en el backlog hasta contar con una prueba que confirme su funcionamiento.
## Activación transaccional de cuentas

La activación conserva exclusivamente RUT, username, colegio y rol de la invitación.
Solo admite alumno/profesor, como la API actual. El colegio debe ser positivo y
estar respaldado por un docente o admin activo: no existe un catálogo `colegios`.
El navegador solo aporta nombre, apellido y contraseña; los identificadores
adicionales enviados por POST no modifican la identidad invitada.

La sesión anónima conserva el hash del token y un CSRF específico. El formulario
se envía sin token en la query. PostgreSQL bloquea la invitación con `FOR UPDATE`,
comprueba vigencia con su reloj después del bloqueo y vuelve a comprobarla al
consumirla. INSERT de usuario y UPDATE de usado comparten una transacción.
Las restricciones UNIQUE de usuarios resuelven duplicados concurrentes y cualquier
fallo revierte ambos pasos. No se autentica automáticamente al usuario activado.

Las contraseñas usan `password_hash(PASSWORD_DEFAULT)`, con mínimo de 8 bytes,
máximo de 72 bytes para evitar truncación con bcrypt y rechazo de NUL. No se
registran contraseñas, tokens ni errores SQL. `Referrer-Policy: no-referrer`
evita reenviar el enlace. El formato combined de Apache registra método/ruta,
sin query ni Referer; una infraestructura externa que use otro formato también
debe redactar estos valores antes de desplegar.

`migrations/006_invitaciones.sql` crea solo la tabla que ya usa el contrato de
invitaciones. No ejecutar sobre una tabla existente ni automáticamente al arrancar.
La inspección de DEV detectó su ausencia; no se aplicó allí. Para instalarla en un
entorno autorizado: `psql -X -v ON_ERROR_STOP=1 -f migrations/006_invitaciones.sql`.
No se crean aulas, matrículas o vínculos por activar una cuenta.
