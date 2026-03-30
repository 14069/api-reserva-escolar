<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

$currentTimestampExpression = getCurrentTimestampExpression();
$hasCompletionFeedbackColumn = databaseColumnExists($pdo, 'bookings', 'completion_feedback');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$bookingId = $input['booking_id'] ?? null;
$userId = $input['user_id'] ?? null;
$completionFeedback = trim((string) ($input['completion_feedback'] ?? ''));

if ($completionFeedback === '') {
    $completionFeedback = null;
}

if ($completionFeedback !== null && mb_strlen($completionFeedback) > 500) {
    jsonResponse(false, "O feedback deve ter no máximo 500 caracteres.", null, 400);
}

if (empty($schoolId) || empty($bookingId) || empty($userId)) {
    jsonResponse(false, "school_id, booking_id e user_id são obrigatórios.", null, 400);
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
    jsonResponse(false, "Agendamento não encontrado.", null, 404);
}

if ((int) $result['user_id'] !== (int) $userId && $result['role'] !== 'technician') {
    jsonResponse(false, "Você não tem permissão para finalizar este agendamento.", null, 403);
}

if ($result['status'] === 'cancelled') {
    jsonResponse(false, "Agendamentos cancelados não podem ser finalizados.", null, 400);
}

if ($result['status'] === 'completed') {
    jsonResponse(false, "Este agendamento já foi finalizado.", null, 400);
}

$bookingDate = trim((string) ($result['booking_date'] ?? ''));
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
if ($bookingDate === '' || $bookingDate > $today) {
    jsonResponse(false, "Só é possível finalizar reservas no dia do uso ou depois dele.", null, 400);
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
        500
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
