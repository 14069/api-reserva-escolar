<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$lessonSlotId = $input['lesson_slot_id'] ?? null;

if (empty($schoolId) || empty($userId) || empty($lessonSlotId)) {
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
    jsonResponse(false, "Usuário sem permissão para alterar status de aulas.", null, 403);
}

$stmt = $pdo->prepare("
    SELECT id, active
    FROM lesson_slots
    WHERE id = ?
      AND school_id = ?
");
$stmt->execute([$lessonSlotId, $schoolId]);
$lesson = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    jsonResponse(false, "Aula não encontrada.", null, 404);
}

$newStatus = ((int)$lesson['active'] === 1) ? 0 : 1;

$updateStmt = $pdo->prepare("
    UPDATE lesson_slots
    SET active = ?
    WHERE id = ? AND school_id = ?
");
$updateStmt->execute([$newStatus, $lessonSlotId, $schoolId]);

jsonResponse(true, $newStatus === 1 ? "Aula ativada com sucesso." : "Aula desativada com sucesso.");
