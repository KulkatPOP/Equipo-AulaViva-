# ADR 0005 — Estrategia de integración CI/CD y GitOps

## Estado y alcance

Decisión reflejada en la implementación vigente; documentación reconciliada con `main = fa368fe016d38c80e8f386b5eed9ce8435d14f3b` el 23 de noviembre de 2026. No acredita por sí sola una ejecución remota ni todas las validaciones operativas.

## Contexto

CI y despliegue tienen responsabilidades separadas. CI prueba, analiza, construye y publica la aplicación. GitOps mantiene la configuración declarativa de despliegue y Argo CD reconcilia ese estado con Kubernetes.

La rama histórica S08 contiene controles preparatorios superados por main y no se incorpora directamente. La arquitectura de aplicación permanece en S03; infraestructura y observaciones de entorno, en S04/S07.

## Decisión

Mantener la separación entre:

| Elemento | Responsabilidad |
|---|---|
| Aplicación: `KulkatPOP/Equipo-AulaViva-` | Código, pruebas, Dockerfile, workflows y gates |
| GHCR: `ghcr.io/kulkatpop/aulaviva-api` | Imágenes identificadas por tag SHA; digest usado para verificaciones de imagen |
| GitOps: `kevinramosr/AulaViva-GitOps` | Base y overlays Kustomize; estado deseado por entorno |
| Argo CD | Reconciliación del repositorio GitOps con el clúster |

### Construcción y promoción

`ci.yml` condiciona el build por quality, security y Checkov. La publicación se permite en push a main y a `integration/us01-us04`; las verificaciones S10 y la promoción se limitan a push a main. Esta distinción describe la configuración actual, no una política de publicación exclusiva de main.

Para main, los controles S10 escanean y firman/verifican el digest; DAST utiliza la imagen publicada por digest. La promoción espera build, S10 y DAST, y propone un PR en GitOps actualizando el tag de DEV. No despliega directamente mediante kubectl ni modifica PROD.

`GITHUB_TOKEN` se referencia para GHCR y `GITOPS_TOKEN` para el repositorio de despliegue. No se incorporan sus valores al código; la configuración no demuestra por sí misma que sus permisos efectivos estén vigentes.

La secuencia debe distinguir:

1. Validar el código y sus dependencias.
2. Construir/publicar la imagen y obtener el digest.
3. Ejecutar los controles de imagen y aplicación correspondientes.
4. Proponer la actualización mediante PR GitOps.
5. Revisar e integrar el cambio de estado deseado.
6. Sincronizar y comprobar el entorno.

Un PR generado no demuestra promoción integrada; una imagen publicada no demuestra despliegue. Firma/procedencia en CI no equivalen a enforcement de admisión en DEV.

### Estado observado

En la revisión registrada el 23 de noviembre de 2026, Argo CD mostró `aulvaviva-dev` en `Synced / Healthy`, revisión GitOps `a7a2a7b2d38e31f270311f1dc1e28b3792c439f0`, sin `syncPolicy.automated` configurado.

No se afirma despliegue automático, ejecución del último main ni actualización automática del entorno por cada publicación. El detalle y los límites están en [S07](../infra/s07-reconciliacion.md).

## Alternativas

- Despliegue directo desde CI: no se adopta como ruta principal; mezclaría publicación y modificación del clúster.
- GitOps mediante cambios revisables: mantiene el estado deseado versionado y separa las responsabilidades de CI y reconciliación.

## Consecuencias

- Trazabilidad entre commit, imagen y cambio de despliegue.
- Gates previos a publicación/promoción según contexto.
- Separación de responsabilidades y promoción controlada mediante PR.
- Dependencia de permisos entre repositorios, revisión/integración y sincronización GitOps; la necesidad efectiva de aprobaciones depende de las protecciones vigentes.
- La disponibilidad de PostgreSQL/Ollama y las validaciones funcionales del entorno no se deducen del éxito del build.

## Rollback: procedimiento documentado, no validado

Es posible proponer una reversión del cambio de imagen/configuración en GitOps, revisar/integrar ese cambio, sincronizar Argo CD y verificar la aplicación. No se ha acreditado aquí un ensayo de rollback. La compatibilidad de migraciones y datos debe revisarse: revertir una imagen no revierte automáticamente la base de datos.

## Relación y validación

S07 documenta infraestructura y estado GitOps; S08 documenta gates y estrategia de promoción. No se duplica el inventario de manifiestos ni se atribuyen controles a namespaces fuera de su alcance.

Las fuentes de configuración son `.github/workflows/ci.yml`, `security.yml`, `supply-chain.yml` y `runtime-lab.yml`. El detalle de capacidades, artefactos y límites se mantiene en [quality gates](../s08-ci-quality-gates.md) y [readiness](../s08-devsecops-readiness.md).

Para acreditar una entrega concreta deben registrarse el run/commit, resultado de gates, imagen/digest, PR GitOps, revisión reconciliada y validación funcional. Este ADR no sustituye esas evidencias.
