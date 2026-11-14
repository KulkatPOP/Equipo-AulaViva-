#!/usr/bin/env bash
# Ejecutar exclusivamente contra el kind desechable creado por runtime-lab.yml.
set -euo pipefail
[[ "$(kubectl config current-context)" == "kind-aulaviva-s10" ]]
[[ "$DIGEST" =~ ^sha256:[a-f0-9]{64}$ ]]
mkdir -p evidence
kubectl create namespace aulaviva
kubectl create namespace kyverno
kubectl create namespace falco
for namespace in aulaviva kyverno falco; do
  kubectl -n "$namespace" create secret docker-registry ghcr-read --docker-server=ghcr.io --docker-username="$GITHUB_ACTOR" --docker-password="$GH_TOKEN"
done
helm repo add kyverno https://kyverno.github.io/kyverno/
helm repo add falcosecurity https://falcosecurity.github.io/charts
helm repo update
helm upgrade --install kyverno kyverno/kyverno --version "$KYVERNO_CHART_VERSION" -n kyverno --wait --timeout 6m
# El archivo público no contiene secretos. El laboratorio agrega acceso al paquete privado.
python3 - <<'PY'
from pathlib import Path
s=Path('k8s/policies/require-signature.yaml').read_text()
s=s.replace('          required: true', '          imageRegistryCredentials:\n            secrets: [ghcr-read]\n          required: true')
Path('evidence/require-signature-lab.yaml').write_text(s)
PY
kubectl apply --dry-run=server -f evidence/require-signature-lab.yaml
kubectl apply -f evidence/require-signature-lab.yaml
kubectl wait clusterpolicy/require-image-signature --for=condition=Ready --timeout=120s
# Esperar que los webhooks observen la política antes de las pruebas.
kubectl -n aulaviva run admission-signed --image="$IMAGE" --restart=Never --dry-run=server -o yaml > evidence/admission-signed.yaml
# Generar un digest distinto en el MISMO registro, deliberadamente sin firma.
# No se despliega ni promueve esta imagen de prueba.
docker pull "$IMAGE"
mkdir -p "$RUNNER_TEMP/unsigned"
printf 'ARG BASE\nFROM ${BASE}\nLABEL aulaviva.lab.unsigned="%s"\n' "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" > "$RUNNER_TEMP/unsigned/Dockerfile"
unsigned_tag="ghcr.io/${GITHUB_REPOSITORY_OWNER,,}/aulaviva-api:unsigned-lab-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
docker build --build-arg BASE="$IMAGE" -f "$RUNNER_TEMP/unsigned/Dockerfile" -t "$unsigned_tag" "$RUNNER_TEMP/unsigned"
docker push "$unsigned_tag"
unsigned=$(docker inspect --format '{{index .RepoDigests 0}}' "$unsigned_tag")
echo "$unsigned" > evidence/unsigned-image.txt
if kubectl -n aulaviva run admission-unsigned --image="$unsigned" --restart=Never --dry-run=server -o yaml > evidence/admission-unsigned.txt 2>&1; then
  echo 'ERROR: se admitio una imagen sin firma'; exit 1
fi
# No aceptar un rechazo solo por registro incorrecto o digest inexistente.
grep -q 'verify-official-workflow' evidence/admission-unsigned.txt
cat evidence/admission-unsigned.txt
# Receptor local que registra incidentes reales del laboratorio en /tmp.
# No envía información a servicios de terceros.
kubectl -n falco create configmap lab-receiver --from-file=index.php=deploy/lab-receiver.php
cat <<EOF | kubectl apply -f -
apiVersion: v1
kind: Pod
metadata:
  name: incident-receiver
  namespace: falco
  labels: {app: incident-receiver}
spec:
  imagePullSecrets: [{name: ghcr-read}]
  containers:
    - name: receiver
      image: $IMAGE
      command: [php, -S, '0.0.0.0:8081', -t, /receiver]
      volumeMounts: [{name: receiver, mountPath: /receiver, readOnly: true}]
  volumes:
    - name: receiver
      configMap: {name: lab-receiver}
---
apiVersion: v1
kind: Service
metadata: {name: incident-receiver, namespace: falco}
spec:
  selector: {app: incident-receiver}
  ports: [{port: 8081, targetPort: 8081}]
EOF
kubectl -n falco wait pod/incident-receiver --for=condition=Ready --timeout=180s
helm upgrade --install falco falcosecurity/falco --version "$FALCO_CHART_VERSION" -n falco \
  -f falco/values.yaml \
  --set-file 'customRules.rules-custom\.yaml=falco/rules-custom.yaml' \
  --set-string falcosidekick.config.webhook.address=http://incident-receiver.falco.svc.cluster.local:8081 \
  --wait --timeout 6m
cat <<EOF | kubectl apply -f -
apiVersion: v1
kind: Pod
metadata:
  name: runtime-fixture
  namespace: aulaviva
  labels: {app: aulaviva}
spec:
  imagePullSecrets: [{name: ghcr-read}]
  containers:
    - name: app
      image: $IMAGE
      env: [{name: DB_DSN, value: 'sqlite:/tmp/staging.sqlite'}]
EOF
kubectl -n aulaviva wait pod/runtime-fixture --for=condition=Ready --timeout=180s
# Reintentar estímulos de laboratorio hasta que los metadatos se hayan enriquecido.
# Se ejecutan en un pod nuevo y sin datos reales.
for attempt in $(seq 1 12); do
  script -q -c 'kubectl exec -it -n aulaviva runtime-fixture -- sh -c "echo AulaViva-lab-shell"' /dev/null
  kubectl -n aulaviva exec runtime-fixture -- sh -c 'echo AulaViva-lab > /etc/aulaviva-lab-test'
  kubectl -n aulaviva exec runtime-fixture -- php -r '$s=fsockopen("incident-receiver.falco.svc.cluster.local",8081,$errno,$errstr,3); if (!$s) {exit(1);} fwrite($s,"GET / HTTP/1.0\r\nHost: lab\r\n\r\n"); fclose($s);'
  sleep 5
  kubectl -n falco exec incident-receiver -- cat /tmp/incidents.ndjson > evidence/incidents.ndjson 2>/dev/null || true
  if python3 scripts/check_lab_incidents.py evidence/incidents.ndjson; then
    kubectl -n aulaviva get pod runtime-fixture -o wide > evidence/runtime-pod.txt
    helm list -A > evidence/helm-versions.txt
    echo 'Admisión positiva, rechazo sin firma y tres incidentes de Falco comprobados.' | tee evidence/runtime-result.txt
    exit 0
  fi
done
echo 'ERROR: no llegaron las tres reglas al receptor; revisar evidencia y driver/metadatos.'
exit 1
