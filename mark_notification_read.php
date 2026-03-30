<?php
require_once 'response.php';
require_once 'db.php';

$currentTimestampExpression = getCurrentTimestampExpression();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();
$schoolId = $input['school_id'] ?? null;
$notificationId = $input['notification_id'] ?? null;

if (empty($schoolId) || empty($notificationId)) {
    jsonResponse(false, "school_id e notification_id são obrigatórios.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);

$updateStmt = $pdo->prepare("
    UPDATE notifications
    SET read_at = COALESCE(read_at, $currentTimestampExpression)
    WHERE id = ?
      AND school_id = ?
      AND user_id = ?
");
$updateStmt->execute([(int) $notificationId, (int) $schoolId, (int) $authUser['id']]);

if ($updateStmt->rowCount() === 0) {
    jsonResponse(false, "Notificação não encontrada.", null, 404);
}

jsonResponse(true, "Notificação marcada como lida.");
