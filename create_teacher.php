<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$password = trim($input['password'] ?? '');

if (empty($schoolId) || empty($userId) || empty($name) || empty($email) || empty($password)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if (!isValidEmailAddress($email)) {
    jsonResponse(false, "Email inválido.", null, 400);
}

if (strlen($password) < 6) {
    jsonResponse(false, "A senha deve ter ao menos 6 caracteres.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');
$userId = (int)$authUser['id'];

$checkUser = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND school_id = ?
      AND role = 'technician'
      AND active = 1
");
$checkUser->execute([$userId, $schoolId]);

if (!$checkUser->fetch()) {
    jsonResponse(false, "Usuário sem permissão para cadastrar professores.", null, 403);
}

$checkEmail = $pdo->prepare("
    SELECT id
    FROM users
    WHERE school_id = ?
      AND email = ?
");
$checkEmail->execute([$schoolId, $email]);

if ($checkEmail->fetch()) {
    jsonResponse(false, "Já existe um usuário com esse email nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    INSERT INTO users (school_id, name, email, password, role, active)
    VALUES (?, ?, ?, ?, 'teacher', 1)
");
$stmt->execute([
    $schoolId,
    $name,
    $email,
    password_hash($password, PASSWORD_DEFAULT)
]);

jsonResponse(true, "Professor cadastrado com sucesso.", [
    "teacher_id" => $pdo->lastInsertId()
], 201);
