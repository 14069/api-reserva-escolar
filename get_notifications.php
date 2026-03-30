<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$unreadOnly = ($_GET['unread_only'] ?? '0') === '1';
$pagination = getPaginationParams(20, 100);

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);

$fromSql = "
    FROM notifications
    WHERE school_id = ?
      AND user_id = ?
";
$params = [(int) $schoolId, (int) $authUser['id']];

if ($unreadOnly) {
    $fromSql .= " AND read_at IS NULL";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $fromSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$unreadCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE school_id = ?
      AND user_id = ?
      AND read_at IS NULL
");
$unreadCountStmt->execute([(int) $schoolId, (int) $authUser['id']]);
$unreadCount = (int) $unreadCountStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT
        id,
        type,
        title,
        message,
        booking_id,
        metadata_json,
        read_at,
        created_at
    " . $fromSql . "
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([...$params, $pagination['page_size'], $pagination['offset']]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($notifications as &$notification) {
    $metadataJson = $notification['metadata_json'] ?? null;
    if (is_string($metadataJson) && trim($metadataJson) !== '') {
        $decoded = json_decode($metadataJson, true);
        $notification['metadata'] = is_array($decoded) ? $decoded : null;
    } else {
        $notification['metadata'] = null;
    }
    unset($notification['metadata_json']);
}

jsonResponse(
    true,
    "Notificações listadas com sucesso.",
    $notifications,
    200,
    buildPaginationMeta(
        $total,
        $pagination['page'],
        $pagination['page_size'],
        ['unread_count' => $unreadCount]
    )
);
