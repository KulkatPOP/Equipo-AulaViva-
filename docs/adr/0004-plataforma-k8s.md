# ADR 0004: Selección de Plataforma Kubernetes e IaC

## Estado
Aprobado

## Contexto
El sistema AulaViva requiere un entorno de orquestación que soporte alta disponibilidad, escalabilidad ante carga variable de estudiantes y consistencia en los despliegues. 

## Decisión
1. **Contenedores Runtime:** Se adopta `gcr.io/distroless/nodejs20-debian12` como imagen base de producción para reducir la superficie de ataque (sin shell) y minimizar el peso a < 200MB.
2. **Orquestador Local:** Se utilizará el clúster integrado de **Docker Desktop**.
3. **Infraestructura como Código (IaC):** Se selecciona **Terraform** por sobre CloudFormation o Pulumi debido a su sintaxis declarativa (HCL) y su capacidad para gestionar el Namespace y Secrets base de Kubernetes.

## Consecuencias
* **Positivas:** Seguridad mejorada al usar tags inmutables por digest y autoescalado eficiente mediante HPA basado en CPU al 65%.
* **Riesgos:** La curva de aprendizaje de Terraform requiere buenas prácticas en el equipo.
