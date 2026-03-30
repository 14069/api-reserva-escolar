<?php

function createNotificationForUser(
    PDO $pdo,
    int $schoolId,
    int $userId,
    string $type,
    string $title,
    string $message,
    ?int $bookingId = null,
    array $metadata = []
): void {
    $insertStmt = $pdo->prepare("
        INSERT INTO notifications (
            school_id,
            user_id,
            type,
            title,
            message,
            booking_id,
            metadata_json
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $insertStmt->execute([
        $schoolId,
        $userId,
        $type,
        $title,
        $message,
        $bookingId,
        empty($metadata)
            ? null
            : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

function getSchoolTechnicianIds(PDO $pdo, int $schoolId, array $excludeUserIds = []): array {
    $sql = "
        SELECT id
        FROM users
        WHERE school_id = ?
          AND role = 'technician'
          AND active = 1
    ";

    $params = [$schoolId];
    if (!empty($excludeUserIds)) {
        $placeholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
        $sql .= " AND id NOT IN ($placeholders)";
        $params = array_merge($params, array_map('intval', $excludeUserIds));
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function fetchBookingNotificationContext(PDO $pdo, int $schoolId, int $bookingId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            b.id,
            b.school_id,
            b.user_id,
            b.booking_date,
            b.status,
            b.purpose,
            r.name AS resource_name,
            u.name AS user_name,
            cg.name AS class_group_name,
            s.name AS subject_name
        FROM bookings b
        INNER JOIN resources r ON r.id = b.resource_id
        INNER JOIN users u ON u.id = b.user_id
        INNER JOIN class_groups cg ON cg.id = b.class_group_id
        INNER JOIN subjects s ON s.id = b.subject_id
        WHERE b.school_id = ?
          AND b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$schoolId, $bookingId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function notifyTechniciansAboutBookingEvent(
    PDO $pdo,
    int $schoolId,
    int $bookingId,
    string $type,
    int $actorUserId,
    ?string $completionFeedback = null
): void {
    $context = fetchBookingNotificationContext($pdo, $schoolId, $bookingId);
    if (!$context) {
        return;
    }

    $resourceName = trim((string) ($context['resource_name'] ?? 'Recurso'));
    $userName = trim((string) ($context['user_name'] ?? 'Professor'));
    $bookingDate = trim((string) ($context['booking_date'] ?? ''));
    $feedback = trim((string) ($completionFeedback ?? ''));
    $dateLabel = $bookingDate !== '' ? $bookingDate : 'data não informada';

    $title = 'Atualização de agendamento';
    $message = $userName . ' atualizou o agendamento de ' . $resourceName . '.';

    switch ($type) {
        case 'booking_created':
            $title = 'Novo agendamento criado';
            $message = $userName . ' agendou ' . $resourceName . ' para ' . $dateLabel . '.';
            break;
        case 'booking_cancelled':
            $title = 'Agendamento cancelado';
            $message = $userName . ' cancelou o agendamento de ' . $resourceName . ' em ' . $dateLabel . '.';
            break;
        case 'booking_completed':
            $title = 'Agendamento finalizado';
            $message = $userName . ' finalizou o agendamento de ' . $resourceName . '.';
            if ($feedback !== '') {
                $message .= ' Feedback: ' . $feedback;
            }
            break;
    }

    $technicianIds = getSchoolTechnicianIds($pdo, $schoolId);
    if (empty($technicianIds)) {
        return;
    }

    $metadata = [
        'resource_name' => $resourceName,
        'booking_date' => $bookingDate,
        'user_name' => $userName,
        'class_group_name' => $context['class_group_name'] ?? '',
        'subject_name' => $context['subject_name'] ?? '',
    ];
    if ($feedback !== '') {
        $metadata['completion_feedback'] = $feedback;
    }

    foreach ($technicianIds as $technicianId) {
        createNotificationForUser(
            $pdo,
            $schoolId,
            $technicianId,
            $type,
            $title,
            $message,
            $bookingId,
            $metadata
        );
    }
}

function hasNotificationBeenSentToday(
    PDO $pdo,
    int $schoolId,
    int $userId,
    string $type,
    int $bookingId
): bool {
    $createdAtDateExpression = getDateOnlyExpression('created_at');
    $currentDateExpression = getCurrentDateExpression();

    $stmt = $pdo->prepare("
        SELECT 1
        FROM notifications
        WHERE school_id = ?
          AND user_id = ?
          AND type = ?
          AND booking_id = ?
          AND $createdAtDateExpression = $currentDateExpression
        LIMIT 1
    ");
    $stmt->execute([$schoolId, $userId, $type, $bookingId]);

    return (bool) $stmt->fetchColumn();
}

function sendBookingCompletionReminderNotifications(PDO $pdo): array {
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $nowTime = (new DateTimeImmutable())->format('H:i:s');

    $stmt = $pdo->query("
        SELECT
            b.id,
            b.school_id,
            b.user_id,
            b.booking_date,
            r.name AS resource_name,
            MAX(ls.end_time) AS latest_end_time
        FROM bookings b
        INNER JOIN resources r ON r.id = b.resource_id
        INNER JOIN booking_lessons bl ON bl.booking_id = b.id
        INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
        WHERE b.status = 'scheduled'
        GROUP BY b.id, b.school_id, b.user_id, b.booking_date, r.name
        ORDER BY b.booking_date ASC, latest_end_time ASC, b.id ASC
    ");

    $createdCount = 0;
    $createdBookingIds = [];
    $evaluatedCount = 0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
        $evaluatedCount++;
        $bookingId = (int) ($booking['id'] ?? 0);
        $schoolId = (int) ($booking['school_id'] ?? 0);
        $userId = (int) ($booking['user_id'] ?? 0);
        $bookingDate = trim((string) ($booking['booking_date'] ?? ''));
        $resourceName = trim((string) ($booking['resource_name'] ?? 'recurso'));
        $latestEndTime = trim((string) ($booking['latest_end_time'] ?? ''));

        $isOverdue = false;
        if ($bookingDate !== '' && $bookingDate < $today) {
            $isOverdue = true;
        } elseif ($bookingDate === $today && $latestEndTime !== '' && $latestEndTime <= $nowTime) {
            $isOverdue = true;
        }

        if (!$isOverdue) {
            continue;
        }

        if (hasNotificationBeenSentToday($pdo, $schoolId, $userId, 'booking_reminder_complete', $bookingId)) {
            continue;
        }

        createNotificationForUser(
            $pdo,
            $schoolId,
            $userId,
            'booking_reminder_complete',
            'Finalize seu agendamento',
            'O período reservado de ' . $resourceName . ' já terminou. Finalize o agendamento para liberar o recurso.',
            $bookingId,
            [
                'resource_name' => $resourceName,
                'booking_date' => $bookingDate,
                'latest_end_time' => $latestEndTime,
            ]
        );

        $createdCount++;
        $createdBookingIds[] = $bookingId;
    }

    return [
        'evaluated_count' => $evaluatedCount,
        'created_count' => $createdCount,
        'booking_ids' => $createdBookingIds,
    ];
}

function requireCronAccess(): void {
    $configuredToken = trim((string) getRuntimeConfigValue('RESERVA_CRON_TOKEN', ''));
    $providedToken = trim((string) ($_SERVER['HTTP_X_RESERVA_CRON_TOKEN'] ?? ($_GET['cron_token'] ?? '')));
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($configuredToken !== '') {
        if ($providedToken !== $configuredToken) {
            jsonResponse(false, 'Acesso não autorizado ao job.', null, 401);
        }
        return;
    }

    if (!in_array($remoteAddress, ['127.0.0.1', '::1', ''], true)) {
        jsonResponse(false, 'Acesso não autorizado ao job.', null, 401);
    }
}
