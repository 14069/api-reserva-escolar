<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

$currentTimestampExpression = getCurrentTimestampExpression();
$hasCompletionFeedbackColumn = databaseColumnExists($pdo, 'bookings', 'completion_feedback');

requireRequestMethod(['POST']);

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$bookingId = $input['booking_id'] ?? null;
$userId = $input['user_id'] ?? null;
$completionFeedback = trim((string) ($input['completion_feedback'] ?? ''));

if ($completionFeedback === '') {
    $completionFeedback = null;
}

if ($completionFeedback !== null && mb_strlen($completionFeedback) > 500) {
    jsonErrorResponse("O feedback deve ter no máximo 500 caracteres.", 400, 'BOOKING_FEEDBACK_TOO_LONG');
}

if (empty($schoolId) || empty($bookingId) || empty($userId)) {
    jsonErrorResponse("school_id, booking_id e user_id são obrigatórios.", 400, 'BOOKING_COMPLETE_REQUIRED_FIELDS');
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int) $authUser['id'];

$stmt = $pdo->prepare("
    SELECT
        b.id,
        b.user_id,
        b.status,
        b.booking_date,
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
    jsonErrorResponse("Agendamento não encontrado.", 404, 'BOOKING_NOT_FOUND');
}

if ((int) $result['user_id'] !== (int) $userId && $result['role'] !== 'technician') {
    jsonErrorResponse("Você não tem permissão para finalizar este agendamento.", 403, 'BOOKING_COMPLETE_FORBIDDEN');
}

if ($result['status'] === 'cancelled') {
    jsonErrorResponse("Agendamentos cancelados não podem ser finalizados.", 400, 'BOOKING_ALREADY_CANCELLED');
}

if ($result['status'] === 'completed') {
    jsonErrorResponse("Este agendamento já foi finalizado.", 400, 'BOOKING_ALREADY_COMPLETED');
}

$bookingDate = trim((string) ($result['booking_date'] ?? ''));
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
if ($bookingDate === '' || $bookingDate > $today) {
    jsonErrorResponse("Só é possível finalizar reservas no dia do uso ou depois dele.", 400, 'BOOKING_COMPLETE_TOO_EARLY');
}

try {
    if ($hasCompletionFeedbackColumn) {
        $updateStmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'completed',
                completed_at = $currentTimestampExpression,
                completed_by_user_id = ?,
                completion_feedback = ?
            WHERE id = ?
              AND school_id = ?
        ");
        $updateStmt->execute([$userId, $completionFeedback, $bookingId, $schoolId]);
    } else {
        $updateStmt = $pdo->prepare("
            UPDATE bookings
            SET status = 'completed',
                completed_at = $currentTimestampExpression,
                completed_by_user_id = ?
            WHERE id = ?
              AND school_id = ?
        ");
        $updateStmt->execute([$userId, $bookingId, $schoolId]);
    }
} catch (PDOException $e) {
    error_log('Complete booking failed: ' . $e->getMessage());
    jsonResponse(
        false,
        "Não foi possível finalizar o agendamento. Verifique se a tabela bookings possui as colunas de conclusão esperadas.",
        null,
        500,
        [
            'error_code' => 'BOOKING_COMPLETE_SCHEMA_ERROR',
            'status_code' => 500,
        ]
    );
}

notifyTechniciansAboutBookingEvent(
    $pdo,
    (int) $schoolId,
    (int) $bookingId,
    'booking_completed',
    (int) $userId,
    $completionFeedback
);

jsonResponse(true, "Agendamento finalizado com sucesso.");
