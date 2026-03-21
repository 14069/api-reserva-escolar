<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$classGroupId = $input['class_group_id'] ?? null;
$name = trim($input['name'] ?? '');

if (empty($schoolId) || empty($userId) || empty($classGroupId) || empty($name)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
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
    jsonResponse(false, "Usuário sem permissão para editar turmas.", null, 403);
}

$checkGroup = $pdo->prepare("
    SELECT id
    FROM class_groups
    WHERE id = ?
      AND school_id = ?
");
$checkGroup->execute([$classGroupId, $schoolId]);

if (!$checkGroup->fetch()) {
    jsonResponse(false, "Turma não encontrada.", null, 404);
}

$checkName = $pdo->prepare("
    SELECT id
    FROM class_groups
    WHERE school_id = ?
      AND name = ?
      AND id <> ?
");
$checkName->execute([$schoolId, $name, $classGroupId]);

if ($checkName->fetch()) {
    jsonResponse(false, "Já existe outra turma com esse nome nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    UPDATE class_groups
    SET name = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([$name, $classGroupId, $schoolId]);

jsonResponse(true, "Turma atualizada com sucesso.");
