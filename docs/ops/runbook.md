# Runbook operacional — AulaViva

## 1. Propósito

Este documento establece los procedimientos operacionales para verificar, administrar, diagnosticar y recuperar los componentes de AulaViva en el entorno DEMO local.

Los procedimientos se fundamentan en la configuración implementada y las pruebas realizadas durante el Game Day del 24 de noviembre de 2026.

## 2. Identificación del entorno

| Componente | Configuración |
|---|---|
| Sistema operativo | Windows con WSL2 |
| Plataforma de contenedores | Docker Desktop |
| Orquestador | Kubernetes |
| Contexto Kubernetes | docker-desktop |
| Namespace | aulaviva-demo |
| Deployment | aulaviva-api |
| Servicio web | aulaviva-api |
| Contenedor PostgreSQL | aulaviva-demo-db |
| Base de datos | aulaviva_demo |
| Volumen persistente | aulaviva-demo-pgdata |
| Registro local | localhost:5000 |
| Contenedor del registro | aulaviva-registry |
| Puerto web local | 8083 |
| Motor de IA | Ollama |
| Puerto Ollama | 11434 |
| Modelo generativo | qwen3:1.7b |
| Modelo de embeddings | nomic-embed-text |

Los procedimientos de este documento no deben ejecutarse sobre DEV o PROD sin autorización y adaptación previa.

## 3. Requisitos operacionales

- Docker Desktop instalado y en ejecución.
- Kubernetes habilitado.
- PowerShell disponible.
- Ollama instalado.
- Modelos de inteligencia artificial descargados.
- Contenedores y volumen persistente configurados.
- Credenciales DEMO disponibles de manera segura.
- Acceso al repositorio del proyecto.

Directorio de trabajo utilizado durante las pruebas:

```powershell
cd "C:\xampp\htdocs\Equipo-AulaViva-"
```

La ruta corresponde al equipo utilizado durante el Game Day y debe adaptarse en otros computadores.

## 4. Inicio y comprobación del entorno

### 4.1. Verificar Docker

```powershell
docker info
docker ps
```

Si Docker no responde, iniciar Docker Desktop y esperar que el motor esté disponible.

### 4.2. Verificar Kubernetes

```powershell
kubectl --context docker-desktop get nodes
```

Resultado esperado: nodo en estado Ready.

### 4.3. Verificar PostgreSQL

```powershell
docker exec -u postgres aulaviva-demo-db pg_isready
```

Resultado esperado:

```text
/var/run/postgresql:5432 - accepting connections
```

Si el contenedor está detenido:

```powershell
docker start aulaviva-demo-db
```

Si está pausado:

```powershell
docker unpause aulaviva-demo-db
```

### 4.4. Verificar el registro local

```powershell
docker inspect aulaviva-registry --format '{{.State.Status}}'

curl.exe --max-time 5 http://localhost:5000/v2/
```

Si el contenedor existe y está detenido:

```powershell
docker start aulaviva-registry
```

### 4.5. Verificar Ollama

```powershell
ollama list

curl.exe --max-time 5 http://localhost:11434/api/version
```

Comprobar que estén disponibles los modelos qwen3:1.7b y nomic-embed-text.

Si Ollama no está disponible, iniciar la aplicación Ollama o ejecutar `ollama serve` en una terminal independiente, siempre que no exista otra instancia utilizando el puerto.

### 4.6. Verificar AulaViva

```powershell
kubectl --context docker-desktop get pods,deployments,services -n aulaviva-demo -o wide
```

```powershell
kubectl --context docker-desktop rollout status deployment/aulaviva-api -n aulaviva-demo --timeout=120s
```

Resultado esperado: una réplica disponible y un Pod Running 1/1.

### 4.7. Habilitar acceso local

Ejecutar en una terminal independiente:

```powershell
kubectl --context docker-desktop port-forward -n aulaviva-demo svc/aulaviva-api 8083:80 --address 127.0.0.1
```

Mantener la terminal abierta mientras se utiliza la aplicación.

Acceso:

```text
http://localhost:8083/login.php
```

El acceso mediante port-forward está destinado exclusivamente al entorno DEMO local. No constituye una configuración HTTPS de producción.

## 5. Comprobaciones de salud

### 5.1. Liveness

```powershell
curl.exe --max-time 10 -i http://localhost:8083/health/live.php
```

Resultado esperado: HTTP 200 y cuerpo `ok`.

Comprueba que la aplicación responde sin depender de PostgreSQL.

### 5.2. Readiness

```powershell
curl.exe --max-time 10 -i http://localhost:8083/health/ready.php
```

Resultado esperado: HTTP 200 y cuerpo `ok`.

Comprueba la disponibilidad de PostgreSQL mediante una consulta SELECT 1.

Ante indisponibilidad de PostgreSQL, se espera HTTP 503 y cuerpo `unavailable`.

### 5.3. Interpretación

| Liveness | Readiness | Interpretación |
|---|---|---|
| 200 | 200 | Aplicación y base de datos disponibles. |
| 200 | 503 | Aplicación disponible, pero no preparada para operar con PostgreSQL. |
| Sin respuesta | Sin respuesta | Revisar aplicación, Kubernetes y port-forward. |

Los endpoints actuales no comprueban directamente la disponibilidad de Ollama. El Tutor IA requiere una validación independiente.

## 6. Diagnóstico de problemas

### 6.1. Revisar Pods y eventos

```powershell
kubectl --context docker-desktop get pods -n aulaviva-demo -o wide

kubectl --context docker-desktop get events -n aulaviva-demo --sort-by=.metadata.creationTimestamp
```

### 6.2. Revisar registros de la aplicación

```powershell
kubectl --context docker-desktop logs deployment/aulaviva-api -n aulaviva-demo --tail=100
```

### 6.3. Revisar registros de PostgreSQL

```powershell
docker logs --tail 100 aulaviva-demo-db
```

### 6.4. Revisar consumo de recursos

```powershell
docker stats --no-stream

ollama ps
```

Los resultados de Docker Stats no representan necesariamente el consumo completo del computador, Kubernetes y Ollama.

### 6.5. Revisar puerto local

```powershell
Get-NetTCPConnection -LocalPort 8083 -State Listen -ErrorAction SilentlyContinue
```

Si existe un port-forward antiguo, identificar el proceso antes de detenerlo:

```powershell
Get-CimInstance Win32_Process -Filter "Name='kubectl.exe'" |
Select-Object ProcessId, CommandLine
```

Cerrar únicamente el proceso correspondiente al port-forward de AulaViva DEMO.

Después, ejecutar nuevamente el procedimiento de acceso local.

## 7. Recuperación ante fallos

### 7.1. Fallo del Pod

Comprobar el estado del Deployment:

```powershell
kubectl --context docker-desktop get deployment aulaviva-api -n aulaviva-demo

kubectl --context docker-desktop get pods -n aulaviva-demo
```

Kubernetes debe crear automáticamente un Pod de reemplazo cuando el anterior deja de existir.

Si se requiere un reinicio operacional autorizado:

```powershell
kubectl --context docker-desktop rollout restart deployment/aulaviva-api -n aulaviva-demo

kubectl --context docker-desktop rollout status deployment/aulaviva-api -n aulaviva-demo --timeout=120s
```

Comprobar posteriormente los endpoints de salud y reiniciar el port-forward si fuera necesario.

Durante GD01 se comprobó la recuperación automática de un Pod en 12,373 segundos.

### 7.2. Fallo de PostgreSQL

Comprobar el estado del contenedor:

```powershell
docker inspect aulaviva-demo-db --format '{{.State.Status}}'
```

Verificar disponibilidad:

```powershell
docker exec -u postgres aulaviva-demo-db pg_isready
```

Comprobar la dirección IP de PostgreSQL:

```powershell
docker inspect aulaviva-demo-db --format '{{.NetworkSettings.Networks.kind.IPAddress}}'
```

Comparar con el EndpointSlice:

```powershell
kubectl --context docker-desktop get endpointslices -n aulaviva-demo -o wide
```

La dirección del EndpointSlice de PostgreSQL debe coincidir con la dirección del contenedor en la red kind.

Si existe una diferencia, corregir únicamente el EndpointSlice del entorno DEMO después de revisar la configuración. No utilizar direcciones IP antiguas sin comprobarlas.

Esperar que la aplicación recupere el estado Ready:

```powershell
kubectl --context docker-desktop wait --for=condition=Ready pod -l app=aulaviva-api -n aulaviva-demo --timeout=60s
```

Durante GD03, la aplicación permaneció en ejecución con cero reinicios mientras PostgreSQL estaba pausado. La recuperación se realizó manualmente.

### 7.3. Fallo del Tutor IA

Comprobar Ollama:

```powershell
ollama list

curl.exe --max-time 5 http://localhost:11434/api/version
```

Verificar la dirección configurada en la aplicación:

```powershell
kubectl --context docker-desktop exec -n aulaviva-demo deployment/aulaviva-api -- printenv OLLAMA_BASE_URL
```

Valor esperado:

```text
http://host.docker.internal:11434
```

Si se confirma que la configuración es incorrecta, restablecerla:

```powershell
kubectl --context docker-desktop set env deployment/aulaviva-api -n aulaviva-demo OLLAMA_BASE_URL=http://host.docker.internal:11434
```

```powershell
kubectl --context docker-desktop rollout status deployment/aulaviva-api -n aulaviva-demo --timeout=120s
```

Reiniciar el port-forward si corresponde y comprobar el Tutor con una pregunta conocida.

Durante GD02, la aplicación devolvió HTTP 503 controlado ante la indisponibilidad simulada de Ollama.

### 7.4. Error al descargar la imagen

Ante estados ImagePullBackOff o ErrImagePull:

1. Revisar los eventos del Pod.
2. Comprobar que el registro local esté disponible.
3. Verificar que la imagen y su digest existan.
4. Comprobar la conectividad entre Kubernetes y el registro.
5. Evitar sustituir la imagen por una versión desconocida.

Imagen DEMO utilizada durante el Game Day:

```text
localhost:5000/aulaviva-demo-tutor@sha256:0c0efc542a913d1147f5c8f8c044df6e7c7d9f1a8e52a3270bd0aaf17e532d12
```

Durante el despliegue de la versión V3 se observó un error HTTP 500 del mecanismo de descarga por digest.

Como solución temporal, se descargó primero la etiqueta V3 mediante un Pod auxiliar, se comprobó su digest y posteriormente se utilizó la imagen disponible localmente.

La causa definitiva del error del registro intermediario permanece pendiente de investigación.

La imagen DEMO local no debe confundirse con las imágenes firmadas y verificadas mediante el pipeline de CI/CD.

## 8. Generación de respaldos

### 8.1. Crear respaldo lógico

```powershell
$carpeta = "$env:USERPROFILE\AulaViva\backups"

New-Item -ItemType Directory -Force -Path $carpeta | Out-Null

$fecha = Get-Date -Format "yyyyMMdd-HHmmss"

$archivo = "aulaviva-demo-$fecha.dump"

docker exec -u postgres aulaviva-demo-db pg_dump -Fc -d aulaviva_demo -f "/tmp/$archivo"

if ($LASTEXITCODE -ne 0) {
    throw "Error al generar el respaldo."
}

docker cp "aulaviva-demo-db:/tmp/$archivo" "$carpeta\$archivo"

if ($LASTEXITCODE -ne 0) {
    throw "Error al copiar el respaldo."
}
```

### 8.2. Verificar respaldo

```powershell
Get-FileHash "$carpeta\$archivo" -Algorithm SHA256

docker exec aulaviva-demo-db sha256sum "/tmp/$archivo"

docker exec -u postgres aulaviva-demo-db pg_restore -l "/tmp/$archivo"
```

Comparar las huellas obtenidas en Windows y dentro del contenedor.

La inspección del contenido del respaldo no sustituye una prueba real de restauración.

### 8.3. Protección del respaldo

- Mantener los archivos fuera del repositorio Git.
- Restringir el acceso a los respaldos.
- No publicar archivos dump ni credenciales.
- Conservar las huellas de integridad.
- Implementar copias externas cifradas.
- Verificar periódicamente la restauración.

La automatización de respaldos y la copia externa permanecen pendientes.

## 9. Restauración de datos

Seleccionar un respaldo previamente verificado.

La restauración debe realizarse inicialmente en una base independiente, nunca directamente sobre la base DEMO activa.

### 9.1. Preparar destino

```powershell
docker exec -u postgres aulaviva-demo-db psql -d postgres -c "SELECT datname FROM pg_database WHERE datistemplate = false;"
```

Seleccionar un nombre de base de datos que no exista.

Ejemplo:

```powershell
$destino = "aulaviva_dr_" + (Get-Date -Format "yyyyMMdd_HHmmss")

docker exec -u postgres aulaviva-demo-db createdb -T template0 $destino

if ($LASTEXITCODE -ne 0) {
    throw "No se pudo crear la base de destino."
}
```

### 9.2. Restaurar respaldo

El siguiente ejemplo utiliza el respaldo generado en la sección anterior:

```powershell
docker exec -u postgres aulaviva-demo-db pg_restore --exit-on-error --single-transaction --no-owner --no-acl -d $destino "/tmp/$archivo"

if ($LASTEXITCODE -ne 0) {
    throw "La restauracion no fue exitosa."
}
```

### 9.3. Verificar estructura

```powershell
docker exec -u postgres aulaviva-demo-db psql -d $destino -c "SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE';"
```

### 9.4. Verificar extensión vectorial

```powershell
docker exec -u postgres aulaviva-demo-db psql -d $destino -c "SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';"
```

```powershell
docker exec -u postgres aulaviva-demo-db psql -d $destino -c "SELECT vector_dims(embedding) AS dimensiones, COUNT(*) FROM material_fragmentos WHERE embedding IS NOT NULL GROUP BY vector_dims(embedding);"
```

### 9.5. Criterios de recuperación

- Estructuras recuperadas correctamente.
- Registros consistentes con el respaldo.
- Extensión pgvector disponible.
- Embeddings con 768 dimensiones.
- Integridad de registros comprobada.
- Propietarios y permisos autorizados restablecidos antes de utilizar la base.
- Conexión segura y pruebas funcionales aprobadas antes de cualquier cambio de conexión.

Durante GD04 se restauraron 16 tablas y 39 embeddings en 1,28 segundos.

La prueba utilizó las opciones --no-owner y --no-acl. Por tanto, no representa una recuperación completa de permisos e infraestructura.

## 10. Validación del Tutor IA

Desde el directorio del repositorio:

```powershell
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force

$claveDemo = Read-Host "Ingresa la contraseña del alumno DEMO" -AsSecureString

.\scripts\tutor-golden-set.ps1 -Password $claveDemo
```

La autorización de ejecución se limita a la sesión actual de PowerShell y debe utilizarse únicamente para scripts previamente revisados.

Resultados esperados:

- Cinco respuestas correctas con evidencia.
- Tres abstenciones correctas.
- Dos controles de seguridad aprobados.
- Diez casos aprobados.
- Ausencia de errores HTTP.

Revisar manualmente la fidelidad de las respuestas y sus citas.

El script genera evidencias JSON y CSV en `docs/ai/evidencias`.

Las pruebas específicas de aislamiento requieren preparar previamente sus datos de prueba. No deben ejecutarse suponiendo que las aulas y materiales temporales todavía existen.

## 11. Validación funcional por roles

| Rol | Comprobaciones |
|---|---|
| Profesor | Acceso a aulas, alumnos inscritos, materiales y evaluaciones publicadas. |
| Alumno | Acceso a cursos, materiales, resultados y Tutor IA. |
| Apoderado | Consulta del rendimiento del estudiante vinculado. |
| Administrador | Consulta de vinculaciones confirmadas. |

En el conjunto de datos DEMO utilizado durante el Game Day se espera:

- Aula 8 Básico A.
- Un alumno inscrito.
- Tres materiales educativos.
- Una evaluación publicada.
- Una entrega confirmada.
- Puntaje obtenido de 20 sobre 30.
- Calificación 4,5.
- Vinculación confirmada entre apoderado y estudiante.

Estos valores corresponden a los datos de prueba existentes y pueden cambiar cuando se realicen nuevas operaciones autorizadas.

## 12. Seguridad operacional

- No almacenar contraseñas en scripts o documentos.
- No publicar secretos, certificados privados ni archivos dump.
- Utilizar exclusivamente el contexto y namespace autorizados.
- Verificar la identidad del recurso antes de modificarlo.
- Mantener los permisos mínimos necesarios.
- Conservar la validación TLS de PostgreSQL.
- No exponer públicamente el registro local.
- No exponer públicamente el puerto de Ollama.
- No desactivar controles de seguridad para resolver errores operacionales.
- Revisar las evidencias antes de incorporarlas al repositorio.

La configuración HTTP local mediante port-forward no reemplaza el uso de HTTPS en ambientes públicos.

## 13. Gestión de incidentes

Ante un incidente operacional:

1. Registrar fecha, hora y componente afectado.
2. Identificar el impacto sobre los usuarios.
3. Preservar registros y evidencias relevantes.
4. Aplicar el procedimiento de recuperación correspondiente.
5. Verificar el estado de salud.
6. Comprobar persistencia y funcionalidades.
7. Documentar la causa, si se encuentra confirmada.
8. Registrar acciones correctivas y preventivas.

No atribuir una causa definitiva sin evidencias suficientes.

## 14. Apagado controlado

Para finalizar una sesión DEMO:

1. Cerrar las sesiones de los usuarios.
2. Finalizar el port-forward mediante Ctrl+C.
3. Comprobar que no existan operaciones de escritura o restauración en curso.
4. Generar un respaldo si se realizaron cambios relevantes.
5. Cerrar Docker Desktop cuando ya no se requieran los servicios.

No eliminar contenedores, volúmenes, namespaces ni bases de datos como parte del apagado normal.

## 15. Evidencias y referencias

- [Objetivos operacionales](slo.md)
- [Plan de recuperación ante desastres](dr-plan.md)
- [Recuperación del Pod](evidencias/GD01-recuperacion.json)
- [Recuperación del Tutor IA](evidencias/GD02-recuperacion.json)
- [Recuperación de PostgreSQL](evidencias/GD03-despues.txt)
- [Restauración de respaldo](evidencias/GD04-restauracion.json)
- [Validación final de infraestructura](evidencias/GD05-infraestructura.txt)
- [Golden Set posterior al Game Day](../ai/evidencias/golden-set-20261124-232620.json)

## 16. Limitaciones y mantenimiento

Este Runbook documenta procedimientos utilizados y recomendaciones operacionales para el entorno DEMO.

No todos los procedimientos han sido ejecutados como una secuencia integral desde un equipo completamente apagado.

Se encuentra pendiente:

- Automatizar el arranque y apagado.
- Validar el funcionamiento después de reiniciar el computador.
- Automatizar los respaldos.
- Implementar copias externas.
- Validar la recuperación completa sobre otro equipo.
- Implementar monitoreo continuo.
- Resolver definitivamente los errores de descarga de imágenes por digest.

El documento deberá actualizarse cuando cambien los componentes, las configuraciones o los procedimientos de AulaViva.