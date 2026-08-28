# ADR 0002 - Elección de estilo arquitectónico

## Estado

Propuesto

## Contexto

AulaViva necesita una plataforma educativa utilizada por distintas instituciones,
manteniendo separación de datos y permitiendo crecimiento futuro.

## Decisión

Se decide utilizar una arquitectura monolito modular.

## Consecuencias

Ventajas:
- Menor complejidad inicial.
- Más fácil de desarrollar y mantener.

Desventajas:
- Puede requerir separar módulos en el futuro.

## Alternativas descartadas

Microservicios:
- Mayor complejidad operacional para la etapa actual.
