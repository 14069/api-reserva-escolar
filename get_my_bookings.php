<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$userId = $_GET['user_id'] ?? null;

if (empty($schoolId) || empty($userId)) {
    jsonResponse(false, "school_id e user_id são obrigatórios.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int)$authUser['id'];

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.booking_date,
        b.purpose,
        b.status,
        b.cancelled_at,
        r.name AS resource_name,
        cg.name AS class_group_name,
        s.name AS subject_name
    FROM bookings b
    INNER JOIN resources r ON r.id = b.resource_id
    INNER JOIN class_groups cg ON cg.id = b.class_group_id
    INNER JOIN subjects s ON s.id = b.subject_id
    WHERE b.school_id = ?
      AND b.user_id = ?
    ORDER BY b.booking_date DESC, b.id DESC
");
$stmt->execute([$schoolId, $userId]);

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

jsonResponse(true, "Agendamentos do usuário listados com sucesso.", $bookings);
