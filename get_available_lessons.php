<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$resourceId = $_GET['resource_id'] ?? null;
$bookingDate = trim($_GET['booking_date'] ?? '');

if (empty($schoolId) || empty($resourceId) || empty($bookingDate)) {
    jsonResponse(false, "school_id, resource_id e booking_date são obrigatórios.", null, 400);
}

if (!isValidDateString($bookingDate)) {
    jsonResponse(false, "booking_date inválida. Use YYYY-MM-DD.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);

$stmt = $pdo->prepare("
    SELECT
        ls.id,
        ls.lesson_number,
        ls.label,
        ls.start_time,
        ls.end_time
    FROM lesson_slots ls
    WHERE ls.school_id = ?
      AND ls.active = 1
      AND ls.id NOT IN (
          SELECT bl.lesson_slot_id
          FROM booking_lessons bl
          INNER JOIN bookings b ON b.id = bl.booking_id
          WHERE b.school_id = ?
            AND b.resource_id = ?
            AND b.booking_date = ?
            AND b.status = 'scheduled'
      )
    ORDER BY ls.lesson_number ASC
");
$stmt->execute([$schoolId, $schoolId, $resourceId, $bookingDate]);

$lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true, "Aulas disponíveis listadas com sucesso.", $lessons);
