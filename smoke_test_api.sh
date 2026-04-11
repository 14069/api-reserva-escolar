#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
DIAGNOSTIC_TOKEN="${2:-${RESERVA_DIAGNOSTIC_TOKEN:-}}"

if [[ -z "$BASE_URL" ]]; then
  echo "Uso: ./smoke_test_api.sh https://api.seudominio.com.br"
  exit 1
fi

echo "Index:"
curl -fsS "${BASE_URL%/}/"
echo
echo

echo "Health:"
curl -fsS "${BASE_URL%/}/health"
echo
echo

if [[ -n "$DIAGNOSTIC_TOKEN" ]]; then
  echo "DB diagnostic:"
  curl -fsS -H "X-Reserva-Diagnostic-Token: ${DIAGNOSTIC_TOKEN}" \
    "${BASE_URL%/}/check-supabase-connection"
  echo
else
  echo "DB diagnostic skipped. Defina RESERVA_DIAGNOSTIC_TOKEN ou passe o token como 2º argumento."
fi
