<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$currentPassword = trim($input['current_password'] ?? '');
$newPassword = trim($input['new_password'] ?? '');

if (empty($schoolId) || empty($userId) || empty($currentPassword) || empty($newPassword)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if (strlen($newPassword) < 6) {
    jsonResponse(false, "A nova senha deve ter ao menos 6 caracteres.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int) $userId;
$authUserId = (int) $authUser['id'];
$schoolId = (int) $schoolId;

if ($authUserId !== $userId) {
    jsonResponse(false, "Você não tem permissão para alterar esta senha.", null, 403);
}

$checkUser = $pdo->prepare("
    SELECT id, password
    FROM users
    WHERE id = ?
      AND school_id = ?
      AND active = 1
    LIMIT 1
");
$checkUser->execute([$authUserId, $schoolId]);

$user = $checkUser->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    jsonResponse(false, "Usuário não encontrado.", null, 404);
}

if (!password_verify($currentPassword, $user['password'])) {
    jsonResponse(false, "A senha atual informada não confere.", null, 401);
}

if (password_verify($newPassword, $user['password'])) {
    jsonResponse(false, "A nova senha deve ser diferente da atual.", null, 400);
}

$stmt = $pdo->prepare("
    UPDATE users
    SET password = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([
    password_hash($newPassword, PASSWORD_DEFAULT),
    $authUserId,
    $schoolId
]);

jsonResponse(true, "Senha atualizada com sucesso.");
