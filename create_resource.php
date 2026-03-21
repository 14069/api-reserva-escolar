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
$categoryId = $input['category_id'] ?? null;

if (empty($schoolId) || empty($userId) || empty($name) || empty($categoryId)) {
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
    jsonResponse(false, "Usuário sem permissão para cadastrar recursos.", null, 403);
}

$checkCategory = $pdo->prepare("
    SELECT id
    FROM resource_categories
    WHERE id = ?
");
$checkCategory->execute([$categoryId]);

if (!$checkCategory->fetch()) {
    jsonResponse(false, "Categoria inválida.", null, 404);
}

$checkName = $pdo->prepare("
    SELECT id
    FROM resources
    WHERE school_id = ?
      AND name = ?
");
$checkName->execute([$schoolId, $name]);

if ($checkName->fetch()) {
    jsonResponse(false, "Já existe um recurso com esse nome nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    INSERT INTO resources (school_id, category_id, name, active)
    VALUES (?, ?, ?, 1)
");
$stmt->execute([$schoolId, $categoryId, $name]);

jsonResponse(true, "Recurso criado com sucesso.", [
    "resource_id" => $pdo->lastInsertId()
], 201);
