# ADR 0009 — Aislamiento de ambientes y recuperación de datos

## Estado

Aceptado. Documentación retrospectiva de las decisiones de aislamiento y recuperación aplicadas al proyecto.

## Contexto

AulaViva administra información académica, usuarios, materiales educativos, evaluaciones y resultados de estudiantes.

Las modificaciones realizadas durante el desarrollo, las pruebas y las demostraciones pueden afectar la disponibilidad o integridad de la información si todos los ambientes comparten los mismos recursos.

Se requiere separar los ambientes, limitar los privilegios de acceso y disponer de mecanismos de recuperación ante fallos.

## Decisión

Mantener ambientes diferenciados para desarrollo, producción y demostración local.

Cada ambiente debe utilizar su propia configuración de ejecución, credenciales y persistencia, evitando que las pruebas de la demostración modifiquen los datos de producción.

| Ambiente | Propósito |
|---|---|
| DEV | Desarrollo, integración y validación de cambios |
| PROD | Operación del entorno productivo |
| DEMO | Demostración académica y pruebas controladas con datos ficticios |

El entorno DEMO utiliza Kubernetes en Docker Desktop, PostgreSQL independiente y Ollama local.

La demostración no requiere modificar los despliegues existentes de DEV y PROD.

## Alternativas consideradas

### Alternativa 1: compartir una base de datos entre ambientes

Utilizar una única base de datos para desarrollo, producción y demostración.

Ventajas:

- Menor consumo de recursos.
- Configuración inicial más sencilla.

Desventajas:

- Riesgo de modificar información de producción durante las pruebas.
- Mayor impacto ante errores de configuración.
- Dificultad para realizar pruebas destructivas y restauraciones.
- Menor aislamiento de credenciales y permisos.

Se descarta debido al riesgo sobre la integridad y disponibilidad de los datos.

### Alternativa 2: utilizar producción para la demostración

Realizar las pruebas y la presentación directamente sobre el entorno productivo.

Ventajas:

- Permite mostrar el entorno operativo existente.
- Evita aprovisionar una infraestructura adicional.

Desventajas:

- Riesgo de afectar la operación.
- Dependencia de la disponibilidad del entorno productivo.
- Posibilidad de exponer información o configuraciones sensibles.
- Dificultad para simular fallos de forma segura.

Se descarta para la demostración académica.

### Alternativa 3: ambientes independientes

Es la alternativa adoptada.

Permite realizar pruebas, modificar interfaces, cargar datos ficticios y verificar procedimientos de recuperación sin intervenir directamente en producción.

## Configuración y aislamiento

El entorno de demostración considera:

- Namespace independiente `aulaviva-demo`.
- Base de datos independiente `aulaviva_demo`.
- PostgreSQL 16 con pgvector.
- Usuario de aplicación independiente con privilegios restringidos.
- Credenciales aprovisionadas fuera del repositorio.
- Conexión PostgreSQL mediante TLS con verificación del certificado y nombre del servidor.
- Contenedor de aplicación ejecutado sin privilegios de administrador.
- Sistema de archivos raíz de solo lectura.
- Eliminación de capacidades Linux adicionales.
- Perfil seccomp.
- Probes de disponibilidad y preparación.
- Acceso local mediante port-forward de Kubernetes.
- Datos académicos y usuarios ficticios.

La separación mediante namespaces contribuye al aislamiento operativo, pero no constituye por sí sola una frontera de seguridad completa.

## Estrategia de recuperación

Utilizar las herramientas nativas de PostgreSQL para generar y restaurar respaldos.

El procedimiento considera:

1. Generar un respaldo mediante `pg_dump` en formato personalizado.
2. Verificar que el archivo se haya generado correctamente.
3. Inspeccionar su contenido mediante `pg_restore`.
4. Restaurar el respaldo en una base de datos independiente.
5. Comprobar la existencia de las tablas y registros esperados.
6. Validar los flujos funcionales después de una recuperación completa.

La restauración de un respaldo local fue ejecutada sobre una base independiente, verificando la recuperación del esquema y los registros.

La validación funcional completa posterior a la restauración, la medición formal de los tiempos de recuperación y la implementación de respaldos externos quedan pendientes.

## Consecuencias positivas

- Menor riesgo de afectar producción durante las pruebas.
- Separación de datos y credenciales.
- Mayor control sobre los permisos de la aplicación.
- Posibilidad de realizar pruebas de recuperación en ambientes controlados.
- Mayor reproducibilidad de la demostración.
- Reducción del impacto de errores durante el desarrollo.

## Consecuencias negativas y limitaciones

- Mayor consumo de recursos al mantener ambientes independientes.
- Necesidad de administrar configuraciones y credenciales diferenciadas.
- Posibles diferencias entre los ambientes.
- Dependencia del equipo físico para la demostración local.
- Ausencia de alta disponibilidad demostrada en DEMO.
- Los respaldos almacenados únicamente en el mismo equipo no protegen frente a su pérdida física.
- El acceso HTTP local mediante port-forward no equivale a una conexión HTTPS.
- Los objetivos RTO y RPO deben definirse y validarse mediante pruebas específicas.

## Criterios de verificación

- Comprobar que DEMO utiliza una base de datos independiente.
- Verificar que las credenciales de aplicación no poseen privilegios administrativos.
- Confirmar el funcionamiento de TLS en PostgreSQL.
- Comprobar que las conexiones no cifradas sean rechazadas.
- Verificar las restricciones de seguridad del contenedor.
- Generar y restaurar respaldos en una base independiente.
- Validar los registros recuperados.
- Medir los tiempos de recuperación.
- Comprobar que los cambios de DEMO no modifican directamente DEV ni PROD.

## Evidencias relacionadas

Repositorio de aplicación:

- `Dockerfile`
- `config/conexion.php`
- `deploy/security.conf`
- `health/live.php`
- `health/ready.php`
- `docs/postgres-bootstrap.md`
- `docs/arch/deployment.md`
- `terraform/main.tf`

Repositorio AulaViva-GitOps:

- `base/deployment.yaml`
- `overlays/dev/`
- `overlays/prod/`
- `docs/prod-handoff.md`

Las evidencias específicas de recuperación, configuración local y pruebas de resiliencia deberán consolidarse en la documentación operativa.

## Decisiones relacionadas

- ADR 0003 — Estilo arquitectónico.
- ADR 0004 — Modelo de datos y eventos.
- ADR 0005 — Estrategia GitOps.
- ADR 0006 — Supply Chain.
- ADR 0008 — Inferencia de inteligencia artificial local.