#!/usr/bin/env bash
set -euo pipefail
mkdir -p evidence
# La plataforma genera la provenance y esta verificación comprueba su firma original.
gh attestation verify "oci://$DIGEST_REF" --repo "$GITHUB_REPOSITORY" > evidence/platform-provenance.txt
jq -er '.dsseEnvelope.payload' "$BUNDLE" | base64 --decode > evidence/statement.json
jq -e '.predicateType == "https://slsa.dev/provenance/v1"' evidence/statement.json
jq -e --arg sha "${DIGEST_REF##*@sha256:}" 'any(.subject[]; .digest.sha256 == $sha)' evidence/statement.json
jq '.predicate' evidence/statement.json > evidence/provenance.json
# Publicar el mismo predicate en el formato Cosign que verifica la política Kyverno.
cosign attest --yes --type https://slsa.dev/provenance/v1 --predicate evidence/provenance.json "$DIGEST_REF"
identity="https://github.com/$GITHUB_REPOSITORY/.github/workflows/supply-chain.yml@refs/heads/main"
cosign verify --certificate-identity "$identity" --certificate-oidc-issuer https://token.actions.githubusercontent.com "$DIGEST_REF" > evidence/cosign-verify.json
cosign verify-attestation --type https://slsa.dev/provenance/v1 --certificate-identity "$identity" --certificate-oidc-issuer https://token.actions.githubusercontent.com "$DIGEST_REF" > evidence/cosign-attestation.json
printf '%s\n' "$DIGEST_REF" > evidence/image.txt
cat evidence/cosign-verify.json
cat evidence/platform-provenance.txt
