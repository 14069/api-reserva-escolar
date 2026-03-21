<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$teacherId = $input['teacher_id'] ?? null;
$newPassword = trim($input['new_password'] ?? '');

if (empty($schoolId) || empty($userId) || empty($teacherId) || empty($newPassword)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if (strlen($newPassword) < 6) {
    jsonResponse(false, "A nova senha deve ter ao menos 6 caracteres.", null, 400);
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
    jsonResponse(false, "Usuário sem permissão para redefinir senha.", null, 403);
}

$checkTeacher = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND school_id = ?
      AND role = 'teacher'
");
$checkTeacher->execute([$teacherId, $schoolId]);

if (!$checkTeacher->fetch()) {
    jsonResponse(false, "Professor não encontrado.", null, 404);
}

$stmt = $pdo->prepare("
    UPDATE users
    SET password = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([
    password_hash($newPassword, PASSWORD_DEFAULT),
    $teacherId,
    $schoolId
]);

jsonResponse(true, "Senha redefinida com sucesso.");
