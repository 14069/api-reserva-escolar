<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$subjectId = $input['subject_id'] ?? null;

if (empty($schoolId) || empty($userId) || empty($subjectId)) {
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
    jsonResponse(false, "Usuário sem permissão para alterar status de disciplinas.", null, 403);
}

$stmt = $pdo->prepare("
    SELECT id, active
    FROM subjects
    WHERE id = ?
      AND school_id = ?
");
$stmt->execute([$subjectId, $schoolId]);
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    jsonResponse(false, "Disciplina não encontrada.", null, 404);
}

$newStatus = ((int)$subject['active'] === 1) ? 0 : 1;

$updateStmt = $pdo->prepare("
    UPDATE subjects
    SET active = ?
    WHERE id = ? AND school_id = ?
");
$updateStmt->execute([$newStatus, $subjectId, $schoolId]);

jsonResponse(true, $newStatus === 1 ? "Disciplina ativada com sucesso." : "Disciplina desativada com sucesso.");
