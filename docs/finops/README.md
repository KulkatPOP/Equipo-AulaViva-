# FinOps — AulaViva

## 1. Objetivo

Evaluar y optimizar el uso de recursos computacionales de AulaViva,
considerando que el proyecto funciona en infraestructura local mediante
Docker Desktop, Kubernetes y Ollama.

## 2. Infraestructura utilizada

- CPU: AMD Ryzen 3 3100 (4 núcleos y 8 hilos).
- GPU: AMD Radeon RX 570 de 8 GB.
- RAM: 16 GB.
- Contenedores: Docker Desktop.
- Orquestación: Kubernetes.
- Base de datos: PostgreSQL.
- Modelos IA: Qwen3 1.7B y nomic-embed-text.

## 3. FO01 — Optimización de recursos Kubernetes

Se redujo la solicitud de memoria del Deployment aulaviva-api,
manteniendo los límites originales.

| Recurso | Antes | Después |
|---|---:|---:|
| CPU solicitada | 100m | 100m |
| Memoria solicitada | 128 MiB | 96 MiB |
| CPU límite | 500m | 500m |
| Memoria límite | 384 MiB | 384 MiB |

La reserva de memoria disminuyó un 25 %.

Durante las pruebas, el pico de memoria pasó de 79.966.208
a 79.925.248 bytes. No se registraron eventos OOM.

La optimización reduce la reserva declarada, sin modificar
los límites de ejecución.

## 4. FO02 — Optimización de modelos de IA

Se implementó una configuración de descarga automática de
modelos Ollama mediante la variable:

TUTOR_OLLAMA_KEEP_ALIVE_SECONDS=60

Se ejecutó nuevamente el Golden Set y se supervisó la memoria GPU.

Resultados:

- Memoria inicial observada: 2.042,53 MB.
- A los 62 segundos: 335,74 MB.
- A los 67 segundos: 0 MB.
- Modelos descargados: 2.

La configuración permite liberar memoria GPU cuando los modelos
dejan de utilizarse.

## 5. FO03 — Etiquetado y trazabilidad

Se incorporaron etiquetas a los recursos Kubernetes:

- project: aulaviva
- environment: demo
- owner: equipo-aulaviva
- cost-center: proyecto-universitario
- managed-by: manual
- component: backend o database

Las etiquetas permiten identificar los recursos por proyecto,
entorno, responsable y componente.

## 6. FO04 — Estimación de costos operacionales

AulaViva se ejecuta en un computador local, por lo que se
estimó el consumo eléctrico del equipo.

Supuestos:

- Operación diaria: 2 horas.
- Operación mensual: 30 días.
- Tarifa referencial: $260 CLP/kWh.

| Escenario | Potencia | Consumo mensual | Costo mensual |
|---|---:|---:|---:|
| Bajo | 100 W | 6 kWh | $1.560 |
| Medio | 200 W | 12 kWh | $3.120 |
| Alto | 300 W | 18 kWh | $4.680 |

El escenario medio representa una estimación del consumo
del computador completo, no exclusivamente de AulaViva.

Los valores de potencia son supuestos y no mediciones
realizadas mediante un medidor eléctrico.

## 7. Limitaciones

- El entorno evaluado es local y no representa una
  infraestructura productiva en la nube.
- No se incluyen costos de servicios cloud.
- Los costos eléctricos son estimaciones.
- No se dispone de métricas históricas completas de
  consumo energético por aplicación.
- Las optimizaciones se validaron en un entorno DEMO.

## 8. Conclusión

La evaluación FinOps permitió optimizar la reserva de memoria
de Kubernetes, implementar la descarga automática de modelos
de IA y mejorar la identificación de recursos mediante etiquetas.

Además, se estimaron los costos eléctricos asociados a la
ejecución local de AulaViva.

Estas medidas permiten mejorar el aprovechamiento de los
recursos disponibles y establecer una base para futuras
evaluaciones de eficiencia operacional.

## 9. Evidencias

Las evidencias se encuentran organizadas en la carpeta
evidencias/ e incluyen:

- FO01: recursos, memoria y CPU antes y después.
- FO02: configuración de Ollama, mediciones GPU y pruebas.
- FO03: etiquetas de Kubernetes.
- FO04: escenarios de consumo y costos eléctricos.