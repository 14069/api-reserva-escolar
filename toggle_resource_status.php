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

if (!is_numeric($schoolId) || !is_numeric($userId) || !is_numeric($resourceId) ||
    (int)$schoolId <= 0 || (int)$userId <= 0 || (int)$resourceId <= 0) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

$schoolId = (int)$schoolId;
$userId = (int)$userId;
$resourceId = (int)$resourceId;

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
    jsonResponse(false, "Usuário sem permissão para alterar status de recursos.", null, 403);
}

$checkResource = $pdo->prepare("
    SELECT id, active
    FROM resources
    WHERE id = ?
      AND school_id = ?
");
$checkResource->execute([$resourceId, $schoolId]);
$resource = $checkResource->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    jsonResponse(false, "Recurso não encontrado.", null, 404);
}

$newStatus = ((int)$resource['active'] === 1) ? 0 : 1;

$stmt = $pdo->prepare("
    UPDATE resources
    SET active = ?
    WHERE id = ?
      AND school_id = ?
");
$stmt->execute([$newStatus, $resourceId, $schoolId]);

jsonResponse(true, $newStatus === 1 ? "Recurso ativado com sucesso." : "Recurso desativado com sucesso.", [
    "resource_id" => $resourceId,
    "active" => $newStatus
]);
