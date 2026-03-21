<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$resourceId = $input['resource_id'] ?? null;
$name = trim($input['name'] ?? '');
$categoryId = $input['category_id'] ?? null;

if (empty($schoolId) || empty($userId) || empty($resourceId) || empty($name) || empty($categoryId)) {
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
    jsonResponse(false, "Usuário sem permissão para editar recursos.", null, 403);
}

$checkResource = $pdo->prepare("
    SELECT id
    FROM resources
    WHERE id = ?
      AND school_id = ?
");
$checkResource->execute([$resourceId, $schoolId]);

if (!$checkResource->fetch()) {
    jsonResponse(false, "Recurso não encontrado.", null, 404);
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
      AND id <> ?
");
$checkName->execute([$schoolId, $name, $resourceId]);

if ($checkName->fetch()) {
    jsonResponse(false, "Já existe outro recurso com esse nome nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    UPDATE resources
    SET name = ?, category_id = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([$name, $categoryId, $resourceId, $schoolId]);

jsonResponse(true, "Recurso atualizado com sucesso.");
