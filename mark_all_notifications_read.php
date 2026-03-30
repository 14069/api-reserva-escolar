<?php
require_once 'response.php';
require_once 'db.php';

$currentTimestampExpression = getCurrentTimestampExpression();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();
$schoolId = $input['school_id'] ?? null;

if (empty($schoolId)) {
    jsonResponse(false, "school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);

$updateStmt = $pdo->prepare("
    UPDATE notifications
    SET read_at = $currentTimestampExpression
    WHERE school_id = ?
      AND user_id = ?
      AND read_at IS NULL
");
$updateStmt->execute([(int) $schoolId, (int) $authUser['id']]);

jsonResponse(true, "Todas as notificações foram marcadas como lidas.", [
    'updated_count' => $updateStmt->rowCount(),
]);
