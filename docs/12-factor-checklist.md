# 12-Factor Checklist — AulaViva

## Alcance

Evaluación de la implementación de AulaViva en `main = 1882ac5`, contrastada con los manifiestos GitOps y el despliegue DEV observado.

Este documento no declara cumplimiento total de 12-Factor.

Estados utilizados:

- **IMPLEMENTADO:** existe evidencia del mecanismo descrito.
- **PARCIAL:** existe una implementación, pero presenta limitaciones relevantes.
- **PENDIENTE:** no existe implementación demostrada.

### 1. Codebase — IMPLEMENTADO

**Estado actual:** la aplicación se mantiene en un repositorio Git. La configuración de despliegue se mantiene en un repositorio GitOps separado.

**Evidencia:** `KulkatPOP/Equipo-AulaViva-` y `kevinramosr/AulaViva-GitOps`; publicación de imágenes identificadas por el SHA del código.

**Limitaciones:** el commit actual de la aplicación y la imagen desplegada en DEV pueden ser distintos. Un cambio documental en `main` no implica su despliegue.

**Acción pendiente:** mantener identificables el commit de aplicación, el tag de imagen y la revisión GitOps de cada entrega.

### 2. Dependencies — PARCIAL

**Estado actual:** existen manifiestos y archivos de bloqueo de Composer y NPM. El Dockerfile declara PHP, extensiones PDO y Poppler.

**Evidencia:** `composer.json`, `composer.lock`, `package.json`, `package-lock.json` y `Dockerfile`.

**Limitaciones:** Ollama y sus modelos se aprovisionan fuera de la imagen de aplicación. Las instalaciones de paquetes del sistema no están completamente fijadas por versión. La imagen no instala la extensión PHP Redis.

**Acción pendiente:** conservar instrucciones reproducibles para dependencias externas y registrar las versiones utilizadas en cada validación.

### 3. Config — PARCIAL

**Estado actual:** la conexión a la base de datos y la configuración de Ollama utilizan variables de entorno. DEV obtiene las variables `DB_*` mediante referencias a un Secret.

**Evidencia:** `config/conexion.php`, `config/tutor/AIProvider.php` y los patches de `overlays/dev/` en GitOps.

**Limitaciones:** el Secret se aprovisiona fuera de Git. La opción `OLLAMA_ALLOW_DOCKER_HOST=true` habilita explícitamente una dirección concreta; el código no comprueba por sí mismo que el entorno sea DEV.

**Acción pendiente:** mantener documentado el aprovisionamiento de configuración y secretos para cada entorno, sin incorporar sus valores sensibles al repositorio.

### 4. Backing services — PARCIAL

**Estado actual:** PostgreSQL y Ollama son servicios externos al Pod de aplicación. pgvector proporciona almacenamiento y operaciones vectoriales dentro de PostgreSQL. Redis Streams es opcional para publicar el Outbox de rendición.

**Evidencia:** configuración PDO, `config/tutor/`, `migrations/004_tutor_rag.sql` y `cli/rendicion.php`.

**Limitaciones:** el DEV validado depende de servicios accesibles desde Docker Desktop. No se ha demostrado su sustitución transparente por servicios gestionados.

**Acción pendiente:** documentar requisitos de conectividad y operación al trasladar el despliegue a otro entorno.

### 5. Build/release/run — IMPLEMENTADO

**Estado actual:** CI construye la imagen; GHCR almacena el artefacto; GitOps selecciona la imagen; Argo CD sincroniza los manifiestos con Kubernetes.

**Evidencia:** `.github/workflows/ci.yml` y `overlays/dev/kustomization.yaml` del repositorio GitOps.

**Limitaciones:** la promoción genera una propuesta mediante PR. Esto no equivale a su integración ni al despliegue automático. No debe presumirse sincronización automática de Argo CD.

**Acción pendiente:** conservar la separación entre publicación de imagen, aprobación de la promoción y sincronización del entorno.

### 6. Processes — PARCIAL

**Estado actual:** la aplicación web se ejecuta con PHP/Apache. Existen procesos CLI para ingesta y operación de rendición.

**Evidencia:** `Dockerfile`, `cli/tutor_ingestar.php` y `cli/rendicion.php`.

**Limitaciones:** las sesiones PHP utilizan archivos locales. La aplicación no es completamente stateless y DEV no dispone de almacenamiento compartido de sesiones.

**Acción pendiente:** resolver la continuidad de sesiones antes de presentar varias réplicas intercambiables como una capacidad soportada.

### 7. Port binding — IMPLEMENTADO

**Estado actual:** la imagen incluye Apache y expone HTTP en el puerto 80. Kubernetes lo publica internamente mediante un Service.

**Evidencia:** `Dockerfile` y `base/service.yaml` del repositorio GitOps.

**Limitaciones:** el Service es ClusterIP; no constituye por sí solo una publicación externa mediante Ingress o TLS.

**Acción pendiente:** definir la exposición externa cuando exista un entorno que la requiera.

### 8. Concurrency — PARCIAL

**Estado actual:** DEV ejecuta una réplica del Deployment. Los procesos CLI pueden ejecutarse separadamente del proceso web.

**Evidencia:** `base/deployment.yaml`, `overlays/dev/kustomization.yaml` y los comandos CLI existentes.

**Limitaciones:** no existe HPA en el DEV observado. No hay una validación de escalado horizontal y las sesiones locales requieren consideración previa.

**Acción pendiente:** definir y probar concurrencia, sesiones y consumo de recursos antes de aumentar réplicas.

### 9. Disposability — PARCIAL

**Estado actual:** la aplicación se ejecuta en un Deployment de Kubernetes. El despliegue observado utiliza un período de terminación de 30 segundos.

**Evidencia:** Deployment DEV observado y manifiestos GitOps.

**Limitaciones:** no existen readiness, liveness ni startup probes declaradas. No se ha demostrado el comportamiento de solicitudes o procesos en curso durante una terminación.

**Acción pendiente:** definir comprobaciones de salud y validar arranque, terminación y sustitución del Pod.

### 10. Dev/prod parity — PARCIAL

**Estado actual:** existe una imagen de aplicación y overlays separados para DEV y PROD.

**Evidencia:** `Dockerfile`, workflow CI y directorios `overlays/dev/` y `overlays/prod/` del repositorio GitOps.

**Limitaciones:** PROD no se considera validado por la existencia de su overlay. CI utiliza SQLite en parte de sus comprobaciones; esto no reproduce íntegramente PostgreSQL, pgvector y Ollama de DEV.

**Acción pendiente:** documentar diferencias y ampliar las comprobaciones reproducibles sobre las dependencias reales.

### 11. Logs — PARCIAL

**Estado actual:** existen registros de Apache/PHP, salidas operativas de CLI y registros del Tutor. CI conserva informes de sus validaciones.

**Evidencia:** configuración de la imagen, `cli/rendicion.php`, `config/tutor/` y workflows.

**Limitaciones:** no existe evidencia de una plataforma centralizada que reúna todos los registros y métricas de aplicación. No se presenta CloudWatch como servicio activo.

**Acción pendiente:** definir recopilación, retención y consulta de registros sin registrar contraseñas, tokens ni contenido sensible innecesario.

### 12. Admin processes — PARCIAL

**Estado actual:** existen comandos CLI para ingesta de materiales, cierre de rendiciones vencidas y publicación opcional del Outbox.

**Evidencia:** `cli/tutor_ingestar.php`, `cli/rendicion.php`, migraciones y documentación operativa.

**Limitaciones:** no hay Jobs ni CronJobs de aplicación desplegados en el DEV observado. La existencia de un CLI no demuestra su ejecución periódica.

**Acción pendiente:** documentar quién ejecuta cada operación, con qué configuración y cómo se verifica su resultado.

## Balance

AulaViva dispone de mecanismos compatibles con varios factores, especialmente versionado, configuración externa y separación entre construcción y despliegue.

Las principales limitaciones son las sesiones locales, la ausencia de probes y escalado validado, las diferencias entre CI y DEV y la operación externa de servicios y comandos administrativos.
