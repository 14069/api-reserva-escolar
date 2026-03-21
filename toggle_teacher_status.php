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

if (empty($schoolId) || empty($userId) || empty($teacherId)) {
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
    jsonResponse(false, "Usuário sem permissão para alterar status de professores.", null, 403);
}

$stmt = $pdo->prepare("
    SELECT id, active
    FROM users
    WHERE id = ?
      AND school_id = ?
      AND role = 'teacher'
");
$stmt->execute([$teacherId, $schoolId]);
$teacher = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$teacher) {
    jsonResponse(false, "Professor não encontrado.", null, 404);
}

$newStatus = ((int)$teacher['active'] === 1) ? 0 : 1;

$updateStmt = $pdo->prepare("
    UPDATE users
    SET active = ?
    WHERE id = ? AND school_id = ?
");
$updateStmt->execute([$newStatus, $teacherId, $schoolId]);

jsonResponse(true, $newStatus === 1 ? "Professor ativado com sucesso." : "Professor desativado com sucesso.");
