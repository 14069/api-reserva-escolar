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

if (empty($schoolId) || empty($userId) || empty($classGroupId)) {
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
    jsonResponse(false, "Usuário sem permissão para alterar status de turmas.", null, 403);
}

$stmt = $pdo->prepare("
    SELECT id, active
    FROM class_groups
    WHERE id = ?
      AND school_id = ?
");
$stmt->execute([$classGroupId, $schoolId]);
$classGroup = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$classGroup) {
    jsonResponse(false, "Turma não encontrada.", null, 404);
}

$newStatus = ((int)$classGroup['active'] === 1) ? 0 : 1;

$updateStmt = $pdo->prepare("
    UPDATE class_groups
    SET active = ?
    WHERE id = ? AND school_id = ?
");
$updateStmt->execute([$newStatus, $classGroupId, $schoolId]);

jsonResponse(true, $newStatus === 1 ? "Turma ativada com sucesso." : "Turma desativada com sucesso.");
