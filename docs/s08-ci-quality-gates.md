# S08 — CI y quality gates

## Alcance y evidencia

S08 está ampliamente implementada en `main = fa368fe016d38c80e8f386b5eed9ce8435d14f3b`, base revisada el 23 de noviembre de 2026. No se integra ni copia la rama histórica `feature/s08-cicd-gitops`.

Se distingue **IMPLEMENTADO** (configuración/código existente), **OBSERVADO** (ejecución o entorno comprobado), **DOCUMENTADO** (procedimiento descrito), **PROPUESTO** (ampliación futura) y **REQUIERE VERIFICACIÓN** (resultado o condición no acreditado). Esta revisión de configuración no certifica una ejecución remota concreta ni una cifra de cobertura medida.

## Workflows implementados

| Archivo | Responsabilidad |
|---|---|
| `.github/workflows/ci.yml` | Orquesta quality, security, Checkov, Docker, controles S10, ZAP y promoción GitOps |
| `.github/workflows/security.yml` | Reutilizable mediante `workflow_call`: SAST, SCA, inventarios y Secrets |
| `.github/workflows/supply-chain.yml` | Reutilizable: escaneo de imagen publicada por digest, firma y procedencia; recibe imagen y digest del build |
| `.github/workflows/runtime-lab.yml` | Ejecución manual, job limitado a main; laboratorio kind efímero de admisión/runtime, no el DEV persistente |

Los inventarios CycloneDX/SPDX se generan en el job SCA de `security.yml`, no en `supply-chain.yml`. Este último complementa esos inventarios con controles sobre la imagen. No se atribuye un SBOM de imagen a un inventario del repositorio.

## Triggers, condiciones y dependencias

`ci.yml` se inicia por PR hacia main, push a cualquier rama o `workflow_dispatch`. Los jobs siguen sujetos a sus condiciones y dependencias:

| Contexto | Comportamiento configurado, si se cumplen los gates |
|---|---|
| PR hacia main | Quality, security y Checkov; build sin publicación; S10 y promoción omitidos; DAST construye localmente |
| Push a main | Publicación GHCR; S10 sobre el digest; DAST usa imagen publicada por digest; propuesta de actualización GitOps |
| Push a `integration/us01-us04` | Build/publicación; S10 y promoción omitidos; DAST con construcción local |
| Push a otras ramas | Quality, security y Checkov; build-and-push omitido; DAST puede construir localmente según su condición |

No toda ejecución manual publica: el login/publicación exige evento `push` en main o integración. S10 exige push a main; promoción también.

`build-and-push` depende de quality, security e iac-scan. `supply-chain` depende del build; internamente la firma espera el escaneo. `promote` depende de build, supply-chain y DAST y se limita a push a main.

DAST usa `always()` y exige éxito de quality, security e iac-scan; en main exige además éxito de supply-chain. Fuera de main no exige explícitamente éxito de build-and-push y puede reconstruir localmente. Un DAST exitoso no implica que el pipeline completo o la publicación hayan terminado correctamente. Los jobs omitidos por estas condiciones no constituyen por sí mismos fallos.

## Quality: PHP, tests y cobertura

IMPLEMENTADO en `quality`:

- PHP 8.4 con Xdebug y dependencias instaladas mediante Composer.
- `php -l` sobre los PHP de `api/` y `config/`; no es un lint de todos los archivos PHP del repositorio.
- `XDEBUG_MODE=coverage vendor/bin/phpunit --no-progress --coverage-text --coverage-clover coverage.xml`.
- Lectura de métricas Clover: falla si faltan métricas, si no hay statements o si `coveredstatements / statements * 100` es inferior al 70 %.

El umbral corresponde al conjunto de fuentes de `phpunit.xml`, no a cobertura total de todos los flujos ni a un resultado medido en esta revisión. No se utilizan cifras simuladas para acreditar calidad.

## SAST

`security / sast` instala Semgrep **1.161.0**, prueba las reglas propias con fixtures y escanea con `p/owasp-top-ten`, `p/php` y `.semgrep/`. Utiliza `--error --strict --disable-nosem --metrics=off` y excluye los fixtures del escaneo ordinario. Las reglas propias vigentes se mantienen; no se incorporan reglas históricas duplicadas ni un catálogo Node como control principal.

## SCA e inventarios

Trivy **0.70.0**, descargado con comprobación SHA256, genera CycloneDX y SPDX del repositorio incluyendo dependencias de desarrollo. Se comprueba la estructura de los inventarios y la presencia de componentes Composer/npm en CycloneDX.

El informe de vulnerabilidades se genera sin bloquear para poder conservarlo. Un paso posterior bloquea vulnerabilidades HIGH/CRITICAL con corrección disponible (`--ignore-unfixed --exit-code 1`). Las vulnerabilidades sin parche no quedan acreditadas como resueltas por esa política.

## Secrets

Gitleaks **8.30.0**, descargado con comprobación SHA256, ejecuta:

```sh
gitleaks git . --log-opts="--all HEAD --full-history" --redact=100 --exit-code=1
```

El checkout obtiene el historial completo. `.gitleaksignore` contiene excepciones por fingerprints concretos, no una desactivación global de `generic-api-key`; esa regla continúa activa. No se enumeran credenciales ni se alteran excepciones en esta reconciliación.

## IaC

Checkov **3.2.495** analiza `terraform/` con framework Terraform y controles propios de `security/checkov/`. El job falla ante hallazgos y comprueba que se haya evaluado al menos un control exitoso. Esto no equivale a escanear todos los manifiestos del repositorio GitOps ni a demostrar políticas aplicadas en DEV.

## Docker y GHCR

El build espera los gates previos y utiliza el Dockerfile vigente: PHP/Apache con soporte PostgreSQL y Poppler; PostgreSQL/pgvector y Ollama no se incluyen como servicios dentro de la imagen.

La publicación usa `GITHUB_TOKEN`, con permiso `packages: write`, y el tag `ghcr.io/kulkatpop/aulaviva-api:<SHA del commit>`. El paso build expone el digest, utilizado por S10 y DAST de main. Publicar una imagen no equivale a desplegarla.

## DAST

ZAP prepara un contenedor temporal con `DB_DSN=sqlite:/tmp/staging.sqlite` y espera respuesta de `/login.php`. En main usa el digest publicado; fuera de main construye una imagen local. Ejecuta baseline sobre login y `scripts/zap_gate.py` bloquea los riesgos según el informe. El contenedor se elimina al terminar.

Este staging público y temporal no acredita PostgreSQL, el recorrido educativo autenticado ni RAG. La comprobación de login del workflow no convierte esa página en un health endpoint de Kubernetes.

## Supply chain

S10 escanea con Trivy la imagen publicada por digest y bloquea HIGH/CRITICAL con parche. Después firma mediante Cosign y OIDC de GitHub, verifica la firma y genera procedencia con artifact attestations de GitHub.

`scripts/verify_provenance.sh` verifica la attestation, comprueba su sujeto/digest y publica/verifica el predicate con Cosign para compatibilidad con la política de admisión versionada. No se genera manualmente una afirmación de nivel SLSA ni se acredita reproducibilidad bit a bit.

Estas verificaciones son capacidades del workflow. No prueban enforcement de firma/admisión en DEV; el alcance real de namespaces y políticas se documenta en [S07](infra/s07-reconciliacion.md).

## Artefactos declarados

| Artefacto | Productor y contenido |
|---|---|
| `iac-report` | Checkov, `evidence/checkov.json` |
| `semgrep-report` | Informe JSON de SAST |
| `sbom` | CycloneDX y SPDX generados por SCA |
| `sca-report` | Informe JSON de vulnerabilidades |
| `dast-report` | Evidencias ZAP |
| `signatures-and-provenance` | Verificaciones, referencia de imagen y procedencia |
| `runtime-lab-evidence` | Evidencias del laboratorio manual |

Quality genera `coverage.xml`, pero no declara un paso para publicarlo como artifact. La existencia de pasos de upload, algunos con `always()`, no garantiza que un archivo exista si falló un paso previo ni acredita resultados de una ejecución no inspeccionada.

## Limitaciones y futuras ampliaciones

- Quality no configura servicios PostgreSQL/pgvector/Ollama. Suites como activación y apoderados pueden omitirse al faltar sus DSN de prueba.
- CI no ejecuta explícitamente la generación TypeScript/S05 ni las pruebas de deserialización 200/201.
- DAST no navega el recorrido educativo autenticado completo.
- Las acciones externas están referenciadas por SHA, pero catálogos Semgrep, bases Trivy, paquetes descargados y ZAP `stable` introducen entradas variables. No se garantiza reproducibilidad bit a bit.
- Los permisos efectivos de tokens, resultados de runs, rollback y enforcement DEV requieren evidencia específica; no se deducen de YAML.

Las ampliaciones propuestas se resumen en [readiness](s08-devsecops-readiness.md). La estrategia de promoción corresponde al [ADR 0005](adr/0005-gitops-strategy.md).
