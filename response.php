<?php
require_once __DIR__ . '/bootstrap_env.php';

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

function jsonResponse($success, $message = "", $data = null, $statusCode = 200, $meta = null) {
    http_response_code($statusCode);
    $payload = [
        "success" => $success,
        "message" => $message,
        "data" => $data
    ];

    if ($meta !== null) {
        $payload["meta"] = $meta;
    }

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
        echo json_encode($payload);
    }
    exit;
}

function jsonErrorResponse($message, $statusCode = 400, $errorCode = 'API_ERROR', $data = null, $meta = []) {
    $normalizedMeta = is_array($meta) ? $meta : [];
    $normalizedMeta['error_code'] = $errorCode;
    $normalizedMeta['status_code'] = $statusCode;

    jsonResponse(false, $message, $data, $statusCode, $normalizedMeta);
}

function methodNotAllowedResponse($allowedMethods = ['GET']) {
    $allowedMethods = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
    header('Allow: ' . implode(', ', $allowedMethods));

    jsonErrorResponse(
        "Método não permitido.",
        405,
        'METHOD_NOT_ALLOWED',
        null,
        ['allowed_methods' => $allowedMethods]
    );
}

function requireRequestMethod($allowedMethods) {
    $allowedMethods = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    $allowedMethods = array_values(array_unique(array_map('strtoupper', $allowedMethods)));
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if (!in_array($requestMethod, $allowedMethods, true)) {
        methodNotAllowedResponse($allowedMethods);
    }
}

function getJsonInput() {
    $input = json_decode(file_get_contents("php://input"), true);
    return $input ?: [];
}

function requireDiagnosticAccess() {
    $configuredToken = trim((string) getRuntimeConfigValue('RESERVA_DIAGNOSTIC_TOKEN', ''));
    $providedToken = trim((string) ($_SERVER['HTTP_X_RESERVA_DIAGNOSTIC_TOKEN'] ?? ($_GET['diagnostic_token'] ?? '')));

    if ($configuredToken !== '') {
        if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
            jsonErrorResponse("Acesso não autorizado.", 401, 'DIAGNOSTIC_ACCESS_DENIED');
        }
        return;
    }

    $appEnv = strtolower(trim((string) getRuntimeConfigValue('APP_ENV', 'production')));
    if ($appEnv === 'production') {
        jsonErrorResponse("Recurso não disponível.", 404, 'DIAGNOSTIC_UNAVAILABLE');
    }

    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!in_array($remoteAddress, ['127.0.0.1', '::1', ''], true)) {
        jsonErrorResponse("Acesso não autorizado.", 401, 'DIAGNOSTIC_ACCESS_DENIED');
    }
}

function serverErrorResponse($message = "Erro interno do servidor.") {
    jsonErrorResponse($message, 500, 'INTERNAL_SERVER_ERROR');
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
        'https://reserva-escolar.web.app',
        'https://reserva-escolar.firebaseapp.com',
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
    header("Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Vary: Origin");

    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return;
    }

    if (!isOriginAllowed($origin)) {
        jsonErrorResponse("Origem não autorizada.", 403, 'ORIGIN_NOT_ALLOWED');
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

function getPaginationParams($defaultPageSize = 20, $maxPageSize = 100) {
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['page_size']) ? (int) $_GET['page_size'] : $defaultPageSize;

    if ($page < 1) {
        $page = 1;
    }

    if ($pageSize < 1) {
        $pageSize = $defaultPageSize;
    }

    if ($pageSize > $maxPageSize) {
        $pageSize = $maxPageSize;
    }

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
    ];
}

function buildPaginationMeta($total, $page, $pageSize, $summary = []) {
    $total = (int) $total;
    $page = (int) $page;
    $pageSize = (int) $pageSize;
    $totalPages = $pageSize > 0 ? (int) ceil($total / $pageSize) : 0;

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'total_pages' => $totalPages,
        'has_next_page' => $page < $totalPages,
        'summary' => $summary,
    ];
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

function hashAuthToken($token) {
    return hash('sha256', (string) $token);
}

function requireAuthenticatedUser($pdo, $schoolId = null, $requiredRole = null) {
    $token = getBearerToken();
    if (!$token) {
        jsonErrorResponse("Autenticação obrigatória.", 401, 'AUTH_REQUIRED');
    }

    $hashedToken = hashAuthToken($token);

    $stmt = $pdo->prepare("
        SELECT id, school_id, name, email, role, active, api_token, api_token_expires_at
        FROM users
        WHERE api_token IN (?, ?)
        LIMIT 1
    ");
    $stmt->execute([$hashedToken, $token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)($user['active'] ?? 0) !== 1) {
        jsonErrorResponse("Sessão inválida ou expirada.", 401, 'AUTH_SESSION_INVALID');
    }

    if (($user['api_token'] ?? null) === $token) {
        $upgradeTokenStmt = $pdo->prepare("
            UPDATE users
            SET api_token = ?
            WHERE id = ?
        ");
        $upgradeTokenStmt->execute([$hashedToken, $user['id']]);
        $user['api_token'] = $hashedToken;
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

        jsonErrorResponse("Sessão inválida ou expirada.", 401, 'AUTH_SESSION_EXPIRED');
    }

    if ($schoolId !== null && (int)$user['school_id'] !== (int)$schoolId) {
        jsonErrorResponse("Você não tem acesso a esta escola.", 403, 'AUTH_SCHOOL_FORBIDDEN');
    }

    if ($requiredRole !== null && $user['role'] !== $requiredRole) {
        jsonErrorResponse("Você não tem permissão para esta operação.", 403, 'AUTH_ROLE_FORBIDDEN');
    }

    return $user;
}
