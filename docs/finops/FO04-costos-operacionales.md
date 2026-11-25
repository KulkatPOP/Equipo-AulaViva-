# FO04 — Estimación de costos operacionales AulaViva

## 1. Infraestructura

- Procesador: AMD Ryzen 3 3100.
- GPU: AMD Radeon RX 570 8 GB.
- RAM: 16 GB.
- Plataforma: Docker Desktop y Kubernetes.
- Base de datos: PostgreSQL.
- IA: Ollama con Qwen3 1.7B y nomic-embed-text.

## 2. Supuestos

- Horas de operación diarias: 2.
- Días de operación mensual: 30.
- Horas mensuales: 60.
- Tarifa eléctrica referencial: $260 CLP/kWh.

## 3. Escenarios

- Bajo: 100 W.
- Medio: 200 W.
- Alto: 300 W.

## 4. Resultado

El escenario medio estima un consumo de 12 kWh
mensuales y un costo de $3.120 CLP.

## 5. Limitaciones

Los valores de potencia son supuestos de simulación.
No corresponden a mediciones realizadas con un
medidor eléctrico físico.

El cálculo no representa una factura eléctrica real
ni incluye costos de servicios externos o nube.

## 6. Conclusión

AulaViva utiliza infraestructura local, por lo que
su principal costo operacional considerado en este
ejercicio es el consumo eléctrico del computador.
