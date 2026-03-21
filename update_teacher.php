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
$name = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');

if (empty($schoolId) || empty($userId) || empty($teacherId) || empty($name) || empty($email)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if (!isValidEmailAddress($email)) {
    jsonResponse(false, "Email inválido.", null, 400);
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
    jsonResponse(false, "Usuário sem permissão para editar professores.", null, 403);
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

$checkEmail = $pdo->prepare("
    SELECT id
    FROM users
    WHERE school_id = ?
      AND email = ?
      AND id <> ?
");
$checkEmail->execute([$schoolId, $email, $teacherId]);

if ($checkEmail->fetch()) {
    jsonResponse(false, "Já existe outro usuário com esse email nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    UPDATE users
    SET name = ?, email = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([$name, $email, $teacherId, $schoolId]);

jsonResponse(true, "Professor atualizado com sucesso.");
