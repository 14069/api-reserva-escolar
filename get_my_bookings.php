<?php
require_once 'response.php';
require_once 'db.php';

$formattedBookingDateExpression = getFormattedDateSearchExpression('b.booking_date');
$completionFeedbackSelect = databaseColumnExists($pdo, 'bookings', 'completion_feedback')
    ? 'b.completion_feedback'
    : 'NULL AS completion_feedback';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$userId = $_GET['user_id'] ?? null;
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'date_desc');
$shouldPaginate = isset($_GET['page']) || isset($_GET['page_size']);
$pagination = $shouldPaginate ? getPaginationParams() : null;

if (empty($schoolId) || empty($userId)) {
    jsonResponse(false, "school_id e user_id são obrigatórios.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int) $authUser['id'];

$selectSql = "
    SELECT
        b.id,
        b.booking_date,
        b.purpose,
        b.status,
        b.cancelled_at,
        b.completed_at,
        $completionFeedbackSelect,
        r.name AS resource_name,
        uc.name AS completed_by_name,
        cg.name AS class_group_name,
        s.name AS subject_name
";

$fromSql = "
    FROM bookings b
    INNER JOIN resources r ON r.id = b.resource_id
    LEFT JOIN users uc ON uc.id = b.completed_by_user_id
    INNER JOIN class_groups cg ON cg.id = b.class_group_id
    INNER JOIN subjects s ON s.id = b.subject_id
    WHERE b.school_id = ?
      AND b.user_id = ?
";
$params = [$schoolId, $userId];

if ($status !== '') {
    $fromSql .= " AND b.status = ?";
    $params[] = $status;
}

if ($search !== '') {
    $fromSql .= " AND (
        r.name LIKE ?
        OR cg.name LIKE ?
        OR s.name LIKE ?
        OR b.purpose LIKE ?
        OR $formattedBookingDateExpression LIKE ?
    )";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$orderSql = " ORDER BY b.booking_date DESC, b.id DESC";
if ($sort === 'date_asc') {
    $orderSql = " ORDER BY b.booking_date ASC, b.id ASC";
} elseif ($sort === 'resource_asc') {
    $orderSql = " ORDER BY r.name ASC, b.booking_date DESC, b.id DESC";
} elseif ($sort === 'status') {
    $orderSql = " ORDER BY b.status ASC, b.booking_date DESC, b.id DESC";
}

$baseSelectSql = $selectSql . $fromSql . $orderSql;

if ($shouldPaginate) {
    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN b.status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_count,
            SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count
        " . $fromSql);
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare($baseSelectSql . " LIMIT ? OFFSET ?");
    $queryParams = [...$params, $pagination['page_size'], $pagination['offset']];
} else {
    $stmt = $pdo->prepare($baseSelectSql);
    $queryParams = $params;
}

$stmt->execute($queryParams);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($bookings as &$booking) {
    $booking['lessons'] = [];
}
unset($booking);

if (!empty($bookings)) {
    $bookingIds = array_map(static fn($booking) => (int) $booking['id'], $bookings);
    $placeholders = implode(', ', array_fill(0, count($bookingIds), '?'));

    $lessonsStmt = $pdo->prepare("
        SELECT
            bl.booking_id,
            ls.id,
            ls.lesson_number,
            ls.label
        FROM booking_lessons bl
        INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
        WHERE bl.booking_id IN ($placeholders)
        ORDER BY bl.booking_id ASC, ls.lesson_number ASC
    ");
    $lessonsStmt->execute($bookingIds);

    $lessonsByBookingId = [];
    while ($lesson = $lessonsStmt->fetch(PDO::FETCH_ASSOC)) {
        $bookingId = (int) $lesson['booking_id'];
        unset($lesson['booking_id']);
        $lessonsByBookingId[$bookingId][] = $lesson;
    }

    foreach ($bookings as &$booking) {
        $booking['lessons'] = $lessonsByBookingId[(int) $booking['id']] ?? [];
    }
    unset($booking);
}

if ($shouldPaginate) {
    jsonResponse(
        true,
        "Agendamentos do usuário listados com sucesso.",
        $bookings,
        200,
        buildPaginationMeta(
            (int) ($summaryRow['total'] ?? 0),
            $pagination['page'],
            $pagination['page_size'],
            [
                'scheduled_count' => (int) ($summaryRow['scheduled_count'] ?? 0),
                'completed_count' => (int) ($summaryRow['completed_count'] ?? 0),
                'cancelled_count' => (int) ($summaryRow['cancelled_count'] ?? 0),
            ]
        )
    );
}

jsonResponse(true, "Agendamentos do usuário listados com sucesso.", $bookings);
