# S10 — Runtime Security Lab

## 1. Objetivo

Validar controles de seguridad de la cadena de suministro
y detección de comportamientos sospechosos durante la
ejecución de contenedores de AulaViva.

## 2. Entorno de pruebas

- Kubernetes: clúster desechable Kind.
- Admisión: Kyverno 3.9.2.
- Runtime Security: Falco 0.45.2.
- Imagen: ghcr.io/kulkatpop/aulaviva-api.
- Registro: GitHub Container Registry.
- Firma: Sigstore / Cosign.
- Provenance: SLSA.

## 3. Admisión de imágenes

Se aplicó una política Kyverno denominada
require-image-signature.

La política utiliza Enforce y verifica:
- Registro oficial de imágenes.
- Firma de la imagen.
- Identidad del workflow autorizado.
- Digest de la imagen.
- Attestation de provenance SLSA v1.

### 3.1 Imagen firmada

La imagen identificada por digest:

b1a2ebaa40cecd6a476f35562438941941f64930c7d65c8f7cadc36ce5cc3942

fue admitida por Kyverno.

La anotación kyverno.io/verify-images registra
el resultado pass.

### 3.2 Imagen sin firma

Se intentó admitir una imagen sin firma:

ad3345d36be53eb2998e3edcfb144fe185971bcfb97ed85097073e3dbf317801

Kyverno rechazó la solicitud porque no encontró
firmas asociadas a la imagen.

Resultado: control de admisión validado.

## 4. Detección de comportamiento en runtime

Falco fue desplegado correctamente y permitió
registrar eventos asociados al contenedor
runtime-fixture.

Se identificaron las siguientes reglas:

- AulaViva interactive shell.
- AulaViva write configuration.
- AulaViva unexpected outbound port.
- Drop and execute new binary in container.

Las pruebas intencionales de shell interactiva
y escritura de configuración generaron alertas.

También se registraron eventos de red y ejecuciones
de mount durante el inicio del contenedor.

## 5. Análisis de incidentes

El archivo incidents.ndjson contiene 21 registros:

- 4 registros de Drop and execute new binary in container.
- 15 registros de AulaViva unexpected outbound port.
- 1 registro de AulaViva interactive shell.
- 1 registro de AulaViva write configuration.

No deben interpretarse como 21 ataques independientes.

El resumen runtime-result.txt indica tres incidentes de Falco
comprobados durante el escenario de laboratorio.

El análisis detallado del receptor registra 21 eventos:
15 alertas de conexiones locales generadas por Apache,
4 alertas asociadas a operaciones mount del runtime
y 2 eventos correspondientes a las pruebas de shell
interactiva y escritura de configuración.

Los eventos repetidos y las alertas de operaciones
internas requieren ajustes en las reglas Falco.

Los eventos de mount corresponden a operaciones
observadas durante la preparación del contenedor.
Los eventos de red incluyen conexiones locales
y valores que requieren ajuste de la regla.

Las pruebas de shell y escritura de configuración
sí corresponden a comportamientos intencionales
del escenario de laboratorio.

## 6. Estado de los componentes

Las evidencias muestran:

- Kyverno desplegado.
- Falco desplegado.
- Incident receiver desplegado.
- runtime-fixture en estado Running.

Las versiones registradas fueron:
- Kyverno 3.9.2.
- Falco 0.45.2.

## 7. Observaciones y limitaciones

Durante el laboratorio se observaron:

- Advertencia por uso de evt.dir obsoleto en una regla Falco.
- Errores temporales de comunicación del plugin k8smeta.
- Eventos repetidos de red que requieren reducir falsos positivos.
- Eventos de montaje del runtime que deben excluirse
  de la detección de ejecución sospechosa.

Estas observaciones no impidieron completar
el workflow de laboratorio.

## 8. Resultado

El workflow AulaViva S10 Runtime Lab #4 finalizó
con estado Success.

Se comprobó la admisión de una imagen firmada,
el rechazo de una imagen sin firma y la detección
de comportamientos de prueba mediante Falco.

El laboratorio demuestra controles de admisión
y capacidades de detección en runtime dentro
de un entorno Kubernetes desechable.

No representa una validación de producción.

## 9. Evidencias

Ubicación:

evidencias/s10-runtime-lab/

Archivos:
- admission-signed.yaml
- admission-unsigned.txt
- events.txt
- falco.log
- helm-versions.txt
- pods.txt
- incidents.ndjson
- require-signature-lab.yaml
- runtime-pod.txt
- runtime-result.txt
- unsigned-image.txt