<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolCode = trim($input['school_code'] ?? '');
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');

if (empty($schoolCode) || empty($email) || empty($password)) {
    jsonResponse(false, "Código da escola, email e senha são obrigatórios.", null, 400);
}

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

if (!$user) {
    jsonResponse(false, "Usuário não encontrado.", null, 404);
}

if (!password_verify($password, $user['password'])) {
    jsonResponse(false, "Senha inválida.", null, 401);
}

$token = bin2hex(random_bytes(32));
$tokenExpiresAt = getTokenExpiryDateTime();
$updateTokenStmt = $pdo->prepare("
    UPDATE users
    SET api_token = ?,
        api_token_expires_at = ?
    WHERE id = ?
");
$updateTokenStmt->execute([$token, $tokenExpiresAt, $user['id']]);

unset($user['password']);
$user['api_token'] = $token;
$user['api_token_expires_at'] = $tokenExpiresAt;

jsonResponse(true, "Login realizado com sucesso.", $user);
