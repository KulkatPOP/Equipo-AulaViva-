# ADR 0002: Elección de Estilo Arquitectónico para Aula Viva

## Estado
Aceptado — 2026-09-02

## Contexto
Aula Viva es una plataforma SaaS escolar que debe soportar gestión académica, evaluaciones autocorregidas y asistencia mediante un Tutor IA. El equipo de desarrollo es pequeño (menor a 6 integrantes), el presupuesto inicial de infraestructura es acotado y el dominio educativo aún se encuentra en etapa de descubrimiento y validación.

## Decisión
Se adopta una arquitectura de **Monolito Modular** (Modular Monolith) con empaquetado desacoplado por dominios de negocio (Cursos, Evaluaciones, Asistente IA, Usuarios) y una base de datos relacional única compartida mediante esquemas lógicos.

## Consecuencias
* **Positivas (+):** Despliegue único simple; consistencia transaccional ACID nativa; menor sobrecarga operacional y de red; alta velocidad de desarrollo inicial (time-to-market corto).
* **Negativas (-):** Requiere disciplina estricta de límites entre módulos para evitar acoplamiento indebido; el escalamiento de cómputo aplica sobre toda la aplicación monolítica.

## Alternativas Descartadas
* **Microservicios distribuidos desde el día cero:** Descartado por complejidad prematura de orquestación, consistencia eventual y sobrecosto operacional de red y observabilidad para un equipo pequeño.
* **Serverless puro:** Descartado por costos variables impredecibles ante tráfico escolar continuo y problemas de cold start en horas de alta demanda de exámenes.
  
