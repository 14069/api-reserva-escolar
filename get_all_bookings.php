<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$bookingDate = trim($_GET['booking_date'] ?? '');
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$teacher = trim($_GET['teacher'] ?? '');
$resource = trim($_GET['resource'] ?? '');
$classGroup = trim($_GET['class_group'] ?? '');
$sort = trim($_GET['sort'] ?? 'date_desc');
$shouldPaginate = isset($_GET['page']) || isset($_GET['page_size']);
$pagination = $shouldPaginate ? getPaginationParams() : null;

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');

$selectSql = "
    SELECT
        b.id,
        b.booking_date,
        b.purpose,
        b.status,
        b.cancelled_at,
        r.name AS resource_name,
        u.name AS user_name,
        cg.name AS class_group_name,
        s.name AS subject_name
";

$fromSql = "
    FROM bookings b
    INNER JOIN resources r ON r.id = b.resource_id
    INNER JOIN users u ON u.id = b.user_id
    INNER JOIN class_groups cg ON cg.id = b.class_group_id
    INNER JOIN subjects s ON s.id = b.subject_id
    WHERE b.school_id = ?
";

$params = [$schoolId];

if ($bookingDate !== '') {
    $fromSql .= " AND b.booking_date = ?";
    $params[] = $bookingDate;
}

if ($status !== '') {
    $fromSql .= " AND b.status = ?";
    $params[] = $status;
}

if ($teacher !== '') {
    $fromSql .= " AND u.name = ?";
    $params[] = $teacher;
}

if ($resource !== '') {
    $fromSql .= " AND r.name = ?";
    $params[] = $resource;
}

if ($classGroup !== '') {
    $fromSql .= " AND cg.name = ?";
    $params[] = $classGroup;
}

if ($search !== '') {
    $fromSql .= " AND (
        r.name LIKE ?
        OR u.name LIKE ?
        OR cg.name LIKE ?
        OR s.name LIKE ?
        OR b.purpose LIKE ?
        OR DATE_FORMAT(b.booking_date, '%d/%m/%Y') LIKE ?
    )";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$orderSql = " ORDER BY b.booking_date DESC, b.id DESC";
if ($sort === 'date_asc') {
    $orderSql = " ORDER BY b.booking_date ASC, b.id ASC";
} elseif ($sort === 'teacher_asc') {
    $orderSql = " ORDER BY u.name ASC, b.booking_date DESC, b.id DESC";
} elseif ($sort === 'resource_asc') {
    $orderSql = " ORDER BY r.name ASC, b.booking_date DESC, b.id DESC";
}

$baseSelectSql = $selectSql . $fromSql . $orderSql;

if ($shouldPaginate) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) " . $fromSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN b.status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_count,
            SUM(CASE WHEN b.status <> 'scheduled' THEN 1 ELSE 0 END) AS cancelled_count
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

$lessonsStmt = $pdo->prepare("
    SELECT
        ls.id,
        ls.lesson_number,
        ls.label
    FROM booking_lessons bl
    INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
    WHERE bl.booking_id = ?
    ORDER BY ls.lesson_number ASC
");

foreach ($bookings as &$booking) {
    $lessonsStmt->execute([$booking['id']]);
    $booking['lessons'] = $lessonsStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($shouldPaginate) {
    jsonResponse(
        true,
        "Todos os agendamentos listados com sucesso.",
        $bookings,
        200,
        buildPaginationMeta(
            $total,
            $pagination['page'],
            $pagination['page_size'],
            [
                'scheduled_count' => (int) ($summaryRow['scheduled_count'] ?? 0),
                'cancelled_count' => (int) ($summaryRow['cancelled_count'] ?? 0),
            ]
        )
    );
}

jsonResponse(true, "Todos os agendamentos listados com sucesso.", $bookings);
