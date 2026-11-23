# S08 — Estado de preparación DevSecOps

## Alcance

Reconciliación documental contra `main = fa368fe016d38c80e8f386b5eed9ce8435d14f3b`, revisada el 23 de noviembre de 2026. Sustituye los bloqueos de preparación históricos; no integra la rama S08 antigua ni modifica la implementación.

**Implementado** significa configuración existente; **observado** requiere una comprobación concreta. Los procedimientos documentados, propuestas y puntos que requieren verificación no se presentan como resultados medidos. No se inspeccionó un run remoto concreto para certificarlo en esta revisión.

## IMPLEMENTADO

| Capacidad | Fuente |
|---|---|
| PHPUnit y cobertura real Clover con umbral del 70 % | `ci.yml`, job quality; `phpunit.xml` |
| Semgrep y pruebas de reglas | `security.yml`, job sast |
| SCA y SBOM CycloneDX/SPDX | `security.yml`, job sca |
| Gitleaks sobre historial completo | `security.yml`, job secrets |
| Checkov y controles propios | `ci.yml`, job iac-scan |
| Build y publicación Docker/GHCR condicionados | `ci.yml`, job build-and-push |
| DAST ZAP en staging temporal | `ci.yml`, job dast |
| S10: escaneo de imagen, firma y procedencia | `supply-chain.yml`, `scripts/verify_provenance.sh` |
| Propuesta de promoción GitOps mediante PR | `ci.yml`, job promote |
| Laboratorio efímero manual de admisión/runtime | `runtime-lab.yml` |

Las rutas de workflows son relativas a `.github/workflows/`. Versiones, gates, artefactos y condiciones de omisión se detallan en [quality gates](s08-ci-quality-gates.md), sin duplicarlos aquí.

## OBSERVADO EN DEV

La revisión de Argo CD registrada durante la auditoría S08 y la reconciliación S07 comprobó:

- Application `aulvaviva-dev`: `Synced / Healthy`.
- Revisión GitOps `a7a2a7b2d38e31f270311f1dc1e28b3792c439f0`.
- Sin `syncPolicy.automated` configurado.

Son observaciones fechadas, no garantía permanente ni prueba de que el último main esté desplegado. El inventario de infraestructura corresponde a [S07](infra/s07-reconciliacion.md).

## REQUIERE VERIFICACIÓN / NO DEMOSTRADO AQUÍ

- Enforcement de firma y procedencia en el namespace DEV: un workflow de firma no demuestra admisión protegida.
- Rollback realmente ensayado y validado.
- Vigencia, alcance y permisos efectivos de `GITHUB_TOKEN` y `GITOPS_TOKEN`: solo se revisaron referencias/permisos declarados, no valores de secretos.
- Ejecución de suites PostgreSQL y consultas Ollama en quality: el job no configura esos servicios; algunas suites se omiten sin DSN.
- Recorrido educativo autenticado completo en DAST: el staging actual utiliza SQLite temporal y login público.
- Resultado de una ejecución remota específica de los workflows sobre la base revisada.

## PROPUESTO / FUTURO

1. Ampliar pruebas automatizadas con PostgreSQL/pgvector y, cuando corresponda, Ollama en entornos aislados.
2. Incorporar generación/compilación TypeScript y pruebas S05 de HTTP 200/201 al CI.
3. Ampliar DAST con navegación autenticada y datos ficticios controlados.
4. Validar un procedimiento de rollback GitOps y registrar sus resultados.
5. Evaluar fortalecimiento del enforcement según los namespaces y límites descritos en S07.

Son ampliaciones de alcance, no fallos críticos declarados ni capacidades ya implementadas. Requieren autorización y pruebas antes de modificar workflows o recursos.

## Responsabilidades y criterio de cierre documental

Desarrollo/QA mantiene pruebas y compatibilidad; DevSecOps mantiene gates y evidencia; plataforma administra GitOps y comprueba la reconciliación. La documentación debe separar construir/publicar, proponer promoción, integrar el PR y sincronizar/verificar el entorno.

El cierre documental de S08 exige que estas capacidades, límites y condiciones estén descritos sin tests simulados ni resultados inventados. No exige presentar como completadas las ampliaciones anteriores. La decisión de separación y promoción se registra en el [ADR 0005](adr/0005-gitops-strategy.md).
