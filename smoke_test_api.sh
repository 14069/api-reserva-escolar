#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"

if [[ -z "$BASE_URL" ]]; then
  echo "Uso: ./smoke_test_api.sh https://api.seudominio.com.br"
  exit 1
fi

echo "Health:"
curl -fsS "${BASE_URL%/}/health.php"
echo
echo

echo "DB check:"
curl -fsS "${BASE_URL%/}/check_supabase_connection.php"
echo
