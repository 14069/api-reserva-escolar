<?php
require_once 'response.php';
require_once 'db.php';
require_once 'auth_rate_limit.php';

requireRequestMethod(['POST']);

$input = getJsonInput();

$schoolCode = trim($input['school_code'] ?? '');
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');

if (empty($schoolCode) || empty($email) || empty($password)) {
    jsonErrorResponse(
        "Código da escola, email e senha são obrigatórios.",
        400,
        'LOGIN_REQUIRED_FIELDS'
    );
}

$attemptKey = buildLoginAttemptKey($schoolCode, $email, getClientIpAddress());
enforceLoginRateLimit($pdo, $attemptKey);

$stmt = $pdo->prepare("
    SELECT
        u.id,
        u.school_id,
        u.name,
        u.email,
        u.password,
        u.role,
        s.school_name,
        s.school_code
    FROM users u
    INNER JOIN schools s ON s.id = u.school_id
    WHERE s.school_code = ?
      AND u.email = ?
      AND u.active = 1
    LIMIT 1
");
$stmt->execute([$schoolCode, $email]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    registerFailedLoginAttempt($pdo, $attemptKey, $schoolCode, $email, getClientIpAddress());
    jsonErrorResponse("Credenciais inválidas.", 401, 'LOGIN_INVALID_CREDENTIALS');
}

$token = bin2hex(random_bytes(32));
$hashedToken = hashAuthToken($token);
$tokenExpiresAt = getTokenExpiryDateTime();
$updateTokenStmt = $pdo->prepare("
    UPDATE users
    SET api_token = ?,
        api_token_expires_at = ?
    WHERE id = ?
");
$updateTokenStmt->execute([$hashedToken, $tokenExpiresAt, $user['id']]);
clearFailedLoginAttempts($pdo, $attemptKey);

unset($user['password']);
$user['api_token'] = $token;
$user['api_token_expires_at'] = $tokenExpiresAt;

jsonResponse(true, "Login realizado com sucesso.", $user);
