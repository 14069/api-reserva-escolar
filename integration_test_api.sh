#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-}"
DIAGNOSTIC_TOKEN="${2:-${RESERVA_DIAGNOSTIC_TOKEN:-}}"
TEST_SCHOOL_CODE="${RESERVA_TEST_SCHOOL_CODE:-}"
TEST_EMAIL="${RESERVA_TEST_EMAIL:-}"
TEST_PASSWORD="${RESERVA_TEST_PASSWORD:-}"
RUN_WRITE_TESTS="${RESERVA_TEST_RUN_WRITE_TESTS:-0}"
TEST_RESOURCE_ID="${RESERVA_TEST_RESOURCE_ID:-}"
TEST_CLASS_GROUP_ID="${RESERVA_TEST_CLASS_GROUP_ID:-}"
TEST_SUBJECT_ID="${RESERVA_TEST_SUBJECT_ID:-}"
TEST_BOOKING_DATE="${RESERVA_TEST_BOOKING_DATE:-}"
TEST_LESSON_IDS="${RESERVA_TEST_LESSON_IDS:-}"

if [[ -z "$BASE_URL" ]]; then
  echo "Uso: ./integration_test_api.sh https://api.seudominio.com.br"
  exit 1
fi

BASE_URL="${BASE_URL%/}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

LAST_STATUS=""
LAST_BODY=""

print_step() {
  echo
  echo "==> $1"
}

fail() {
  echo "ERRO: $1" >&2
  exit 1
}

request() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local auth_token="${4:-}"
  local output_file="$TMP_DIR/response_body.json"

  local curl_args=(
    -sS
    -X "$method"
    -D -
    -o "$output_file"
    -H 'Accept: application/json'
  )

  if [[ "$method" == "POST" ]]; then
    curl_args+=(-H 'Content-Type: application/json')
  fi

  if [[ -n "$auth_token" ]]; then
    curl_args+=(-H "Authorization: Bearer $auth_token")
  fi

  if [[ -n "$body" ]]; then
    curl_args+=(-d "$body")
  fi

  local headers
  headers="$(curl "${curl_args[@]}" "$BASE_URL/$path")"
  LAST_STATUS="$(printf '%s\n' "$headers" | sed -n 's/^HTTP\/[0-9.]* \([0-9][0-9][0-9]\).*/\1/p' | tail -n 1)"
  LAST_BODY="$(cat "$output_file")"
}

assert_status() {
  local expected="$1"
  [[ "$LAST_STATUS" == "$expected" ]] || fail "Status esperado $expected, recebido $LAST_STATUS. Body: $LAST_BODY"
}

assert_json_expr() {
  local expr="$1"
  local description="$2"
  php -r '
    $json = $argv[1];
    $expr = $argv[2];
    $description = $argv[3];
    $data = json_decode($json, true);
    if (!is_array($data)) {
        fwrite(STDERR, "JSON inválido ao validar {$description}: {$json}\n");
        exit(1);
    }
    $ok = false;
    switch ($expr) {
        case "success_true":
            $ok = (($data["success"] ?? null) === true);
            break;
        case "success_false":
            $ok = (($data["success"] ?? null) === false);
            break;
        case "has_login_invalid_credentials":
            $ok = (($data["meta"]["error_code"] ?? null) === "LOGIN_INVALID_CREDENTIALS");
            break;
        case "has_auth_required":
            $ok = (($data["meta"]["error_code"] ?? null) === "AUTH_REQUIRED");
            break;
        case "has_bookings_array":
            $ok = array_key_exists("data", $data) && is_array($data["data"]);
            break;
    }
    if (!$ok) {
        fwrite(STDERR, "Falha ao validar {$description}: {$json}\n");
        exit(1);
    }
  ' "$LAST_BODY" "$expr" "$description"
}

extract_json_value() {
  local path="$1"
  php -r '
    $json = $argv[1];
    $path = explode(".", $argv[2]);
    $data = json_decode($json, true);
    $value = $data;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            exit(1);
        }
        $value = $value[$segment];
    }
    if (is_array($value)) {
        echo json_encode($value, JSON_UNESCAPED_UNICODE);
    } else {
        echo (string) $value;
    }
  ' "$LAST_BODY" "$path"
}

print_step "HEAD /health.php"
request HEAD "health.php"
assert_status 200

print_step "GET /health.php"
request GET "health.php"
assert_status 200
assert_json_expr success_true "health success"

print_step "Login inválido retorna erro estruturado"
request POST "login.php" '{"school_code":"ETI001","email":"naoexiste@example.com","password":"senhaerrada"}'
assert_status 401
assert_json_expr success_false "login inválido falha"
assert_json_expr has_login_invalid_credentials "error_code de login inválido"

if [[ -n "$DIAGNOSTIC_TOKEN" ]]; then
  print_step "Diagnóstico de banco com token"
  request GET "check_supabase_connection.php?diagnostic_token=${DIAGNOSTIC_TOKEN}"
  assert_status 200
  assert_json_expr success_true "diagnóstico do banco"
fi

if [[ -z "$TEST_SCHOOL_CODE" || -z "$TEST_EMAIL" || -z "$TEST_PASSWORD" ]]; then
  print_step "Login válido e endpoints protegidos ignorados"
  echo "Defina RESERVA_TEST_SCHOOL_CODE, RESERVA_TEST_EMAIL e RESERVA_TEST_PASSWORD para habilitar a parte autenticada."
  exit 0
fi

print_step "Login válido"
request POST "login.php" "$(printf '{"school_code":"%s","email":"%s","password":"%s"}' "$TEST_SCHOOL_CODE" "$TEST_EMAIL" "$TEST_PASSWORD")"
assert_status 200
assert_json_expr success_true "login válido"

AUTH_TOKEN="$(extract_json_value data.api_token)" || fail "Não foi possível extrair api_token do login"
SCHOOL_ID="$(extract_json_value data.school_id)" || fail "Não foi possível extrair school_id do login"
USER_ID="$(extract_json_value data.id)" || fail "Não foi possível extrair user_id do login"

print_step "GET protegido /get_my_bookings.php"
request GET "get_my_bookings.php?school_id=${SCHOOL_ID}&user_id=${USER_ID}" "" "$AUTH_TOKEN"
assert_status 200
assert_json_expr success_true "get_my_bookings success"
assert_json_expr has_bookings_array "lista de reservas"

print_step "GET protegido sem token falha"
request GET "get_my_bookings.php?school_id=${SCHOOL_ID}&user_id=${USER_ID}"
assert_status 401
assert_json_expr success_false "auth obrigatória"
assert_json_expr has_auth_required "error_code AUTH_REQUIRED"

if [[ "$RUN_WRITE_TESTS" != "1" ]]; then
  print_step "Testes de escrita ignorados"
  echo "Defina RESERVA_TEST_RUN_WRITE_TESTS=1 e informe RESOURCE/CLASS_GROUP/SUBJECT/BOOKING_DATE/LESSON_IDS para testar criação de reserva."
  exit 0
fi

[[ -n "$TEST_RESOURCE_ID" ]] || fail "RESERVA_TEST_RESOURCE_ID é obrigatório para testes de escrita"
[[ -n "$TEST_CLASS_GROUP_ID" ]] || fail "RESERVA_TEST_CLASS_GROUP_ID é obrigatório para testes de escrita"
[[ -n "$TEST_SUBJECT_ID" ]] || fail "RESERVA_TEST_SUBJECT_ID é obrigatório para testes de escrita"
[[ -n "$TEST_BOOKING_DATE" ]] || fail "RESERVA_TEST_BOOKING_DATE é obrigatório para testes de escrita"
[[ -n "$TEST_LESSON_IDS" ]] || fail "RESERVA_TEST_LESSON_IDS é obrigatório para testes de escrita"

LESSON_IDS_JSON="$(php -r '$ids = array_values(array_filter(array_map("intval", explode(",", $argv[1])))); echo json_encode($ids);' "$TEST_LESSON_IDS")"
WRITE_BODY="$(php -r '$payload = ["school_id" => (int) $argv[1], "resource_id" => (int) $argv[2], "user_id" => (int) $argv[3], "class_group_id" => (int) $argv[4], "subject_id" => (int) $argv[5], "booking_date" => $argv[6], "purpose" => "Teste automatizado de integração", "lesson_ids" => json_decode($argv[7], true)]; echo json_encode($payload, JSON_UNESCAPED_UNICODE);' "$SCHOOL_ID" "$TEST_RESOURCE_ID" "$USER_ID" "$TEST_CLASS_GROUP_ID" "$TEST_SUBJECT_ID" "$TEST_BOOKING_DATE" "$LESSON_IDS_JSON")"

print_step "POST /create_booking.php"
request POST "create_booking.php" "$WRITE_BODY" "$AUTH_TOKEN"
assert_status 201
assert_json_expr success_true "create_booking success"

CREATED_BOOKING_ID="$(extract_json_value data.booking_id)" || fail "Não foi possível extrair booking_id criado"

print_step "POST /cancel_booking.php"
CANCEL_BODY="$(printf '{"school_id":%s,"booking_id":%s,"user_id":%s}' "$SCHOOL_ID" "$CREATED_BOOKING_ID" "$USER_ID")"
request POST "cancel_booking.php" "$CANCEL_BODY" "$AUTH_TOKEN"
assert_status 200
assert_json_expr success_true "cancel_booking success"

echo
echo "Todos os testes de integração passaram."
