<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE school_id = ?
      AND user_id = ?
      AND read_at IS NULL
");
$stmt->execute([(int) $schoolId, (int) $authUser['id']]);

jsonResponse(true, "Contagem de notificações carregada com sucesso.", [
    'unread_count' => (int) $stmt->fetchColumn(),
]);
