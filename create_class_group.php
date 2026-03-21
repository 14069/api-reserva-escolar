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

if (empty($schoolId) || empty($userId) || empty($name)) {
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
    jsonResponse(false, "Usuário sem permissão para cadastrar turmas.", null, 403);
}

$checkName = $pdo->prepare("
    SELECT id
    FROM class_groups
    WHERE school_id = ?
      AND name = ?
");
$checkName->execute([$schoolId, $name]);

if ($checkName->fetch()) {
    jsonResponse(false, "Já existe uma turma com esse nome nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    INSERT INTO class_groups (school_id, name, active)
    VALUES (?, ?, 1)
");
$stmt->execute([$schoolId, $name]);

jsonResponse(true, "Turma cadastrada com sucesso.", [
    "class_group_id" => $pdo->lastInsertId()
], 201);
