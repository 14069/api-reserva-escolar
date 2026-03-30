<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

$currentTimestampExpression = getCurrentTimestampExpression();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$bookingId = $input['booking_id'] ?? null;
$userId = $input['user_id'] ?? null;

if (empty($schoolId) || empty($bookingId) || empty($userId)) {
    jsonResponse(false, "school_id, booking_id e user_id são obrigatórios.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int)$authUser['id'];

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.user_id,
        b.status,
        u.role
    FROM bookings b
    INNER JOIN users u ON u.id = ?
        AND u.school_id = b.school_id
    WHERE b.id = ?
      AND b.school_id = ?
");
$stmt->execute([$userId, $bookingId, $schoolId]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    jsonResponse(false, "Agendamento não encontrado.", null, 404);
}

if ($result['status'] === 'cancelled') {
    jsonResponse(false, "Este agendamento já foi cancelado.", null, 400);
}

if ($result['status'] === 'completed') {
    jsonResponse(false, "Agendamentos finalizados não podem ser cancelados.", null, 400);
}

if ((int)$result['user_id'] !== (int)$userId && $result['role'] !== 'technician') {
    jsonResponse(false, "Você não tem permissão para cancelar este agendamento.", null, 403);
}

$updateStmt = $pdo->prepare("
    UPDATE bookings
    SET status = 'cancelled',
        cancelled_at = $currentTimestampExpression,
        cancelled_by_user_id = ?
    WHERE id = ?
      AND school_id = ?
");
$updateStmt->execute([$userId, $bookingId, $schoolId]);

notifyTechniciansAboutBookingEvent(
    $pdo,
    (int) $schoolId,
    (int) $bookingId,
    'booking_cancelled',
    (int) $userId
);

jsonResponse(true, "Agendamento cancelado com sucesso.");
