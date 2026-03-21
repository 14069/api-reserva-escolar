<?php
function getRuntimeConfigValue($key, $default = null) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return $default;
}

function jsonResponse($success, $message = "", $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data" => $data
    ]);
    exit;
}

function getJsonInput() {
    $input = json_decode(file_get_contents("php://input"), true);
    return $input ?: [];
}

function serverErrorResponse($message = "Erro interno do servidor.") {
    jsonResponse(false, $message, null, 500);
}

function getAllowedOrigins() {
    $configuredOrigins = trim((string) getRuntimeConfigValue('RESERVA_ALLOWED_ORIGINS', ''));
    if ($configuredOrigins !== '') {
        $origins = array_filter(array_map('trim', explode(',', $configuredOrigins)));
        return array_values(array_unique($origins));
    }

    return [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8080',
        'http://127.0.0.1:8080',
    ];
}

function isOriginAllowed($origin) {
    foreach (getAllowedOrigins() as $allowedOrigin) {
        if ($allowedOrigin === '*') {
            return true;
        }

        if (strcasecmp($origin, $allowedOrigin) === 0) {
            return true;
        }
    }

    $originParts = parse_url($origin);
    if ($originParts === false) {
        return false;
    }

    $host = strtolower((string) ($originParts['host'] ?? ''));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function applyCorsPolicy() {
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Vary: Origin");

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }

    if (!isOriginAllowed($origin)) {
        jsonResponse(false, "Origem não autorizada.", null, 403);
    }

    header("Access-Control-Allow-Origin: $origin");
}

applyCorsPolicy();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function isValidEmailAddress($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function isValidDateString($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function isValidTimeString($time) {
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return false;
    }

    $parsed = DateTime::createFromFormat('H:i:s', $time);
    return $parsed && $parsed->format('H:i:s') === $time;
}

function getAuthorizationHeaderValue() {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['HTTP_AUTHORIZATION']);
    }

    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'authorization') {
                return trim($value);
            }
        }
    }

    return null;
}

function getBearerToken() {
    $authorizationHeader = getAuthorizationHeaderValue();
    if (!$authorizationHeader) {
        return null;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
        return null;
    }

    return trim($matches[1]);
}

function getTokenExpiryDateTime() {
    return (new DateTimeImmutable('+12 hours'))->format('Y-m-d H:i:s');
}

function requireAuthenticatedUser($pdo, $schoolId = null, $requiredRole = null) {
    $token = getBearerToken();
    if (!$token) {
        jsonResponse(false, "Autenticação obrigatória.", null, 401);
    }

    $stmt = $pdo->prepare("
        SELECT id, school_id, name, email, role, active, api_token, api_token_expires_at
        FROM users
        WHERE api_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)($user['active'] ?? 0) !== 1) {
        jsonResponse(false, "Sessão inválida ou expirada.", null, 401);
    }

    if (
        empty($user['api_token_expires_at']) ||
        strtotime($user['api_token_expires_at']) < time()
    ) {
        $clearTokenStmt = $pdo->prepare("
            UPDATE users
            SET api_token = NULL,
                api_token_expires_at = NULL
            WHERE id = ?
        ");
        $clearTokenStmt->execute([$user['id']]);

        jsonResponse(false, "Sessão inválida ou expirada.", null, 401);
    }

    if ($schoolId !== null && (int)$user['school_id'] !== (int)$schoolId) {
        jsonResponse(false, "Você não tem acesso a esta escola.", null, 403);
    }

    if ($requiredRole !== null && $user['role'] !== $requiredRole) {
        jsonResponse(false, "Você não tem permissão para esta operação.", null, 403);
    }

    return $user;
}
