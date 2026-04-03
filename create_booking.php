<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

requireRequestMethod(['POST']);

function fetchBookingConflictsForLessons(PDO $pdo, int $schoolId, int $resourceId, string $bookingDate, array $lessonIds): array {
    if (empty($lessonIds)) {
        return [];
    }

    $lessonPlaceholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $conflictSql = "
        SELECT
            bl.lesson_slot_id,
            ls.label,
            ls.lesson_number
        FROM booking_lessons bl
        INNER JOIN bookings b ON b.id = bl.booking_id
        INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
        WHERE b.school_id = ?
          AND b.resource_id = ?
          AND b.booking_date = ?
          AND b.status = 'scheduled'
          AND bl.lesson_slot_id IN ($lessonPlaceholders)
        ORDER BY ls.lesson_number ASC
    ";
    $conflictStmt = $pdo->prepare($conflictSql);
    $conflictStmt->execute(array_merge([$schoolId, $resourceId, $bookingDate], $lessonIds));

    return $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
}

function respondWithBookingConflict(int $resourceId, string $bookingDate, array $conflicts): void {
    $unavailableLessonIds = array_map(
        static fn($row) => (int) ($row['lesson_slot_id'] ?? 0),
        $conflicts
    );
    $unavailableLessons = array_map(
        static fn($row) => [
            'lesson_slot_id' => (int) ($row['lesson_slot_id'] ?? 0),
            'label' => (string) ($row['label'] ?? ''),
            'lesson_number' => (int) ($row['lesson_number'] ?? 0),
        ],
        $conflicts
    );

    jsonErrorResponse(
        "Esse horário acabou de ser reservado por outro professor.",
        409,
        'BOOKING_CONFLICT',
        [
            'resource_id' => $resourceId,
            'booking_date' => $bookingDate,
            'unavailable_lesson_ids' => $unavailableLessonIds,
            'unavailable_lessons' => $unavailableLessons,
        ]
    );
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$resourceId = $input['resource_id'] ?? null;
$userId = $input['user_id'] ?? null;
$classGroupId = $input['class_group_id'] ?? null;
$subjectId = $input['subject_id'] ?? null;
$bookingDate = trim($input['booking_date'] ?? '');
$purpose = trim($input['purpose'] ?? '');
$lessonIds = $input['lesson_ids'] ?? [];
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));

if (
    empty($schoolId) ||
    empty($resourceId) ||
    empty($userId) ||
    empty($classGroupId) ||
    empty($subjectId) ||
    empty($bookingDate) ||
    !is_array($lessonIds) ||
    count($lessonIds) === 0
) {
    jsonErrorResponse("Dados obrigatórios não informados.", 400, 'BOOKING_REQUIRED_FIELDS');
}

if (!isValidDateString($bookingDate)) {
    jsonErrorResponse("booking_date inválida. Use YYYY-MM-DD.", 400, 'BOOKING_INVALID_DATE');
}

$lessonIds = array_values(array_unique(array_map('intval', $lessonIds)));
if (count($lessonIds) === 0 || in_array(0, $lessonIds, true)) {
    jsonErrorResponse("Uma ou mais aulas selecionadas são inválidas.", 400, 'BOOKING_INVALID_LESSONS');
}
sort($lessonIds);

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$schoolId = (int) $schoolId;
$resourceId = (int) $resourceId;
$classGroupId = (int) $classGroupId;
$subjectId = (int) $subjectId;
$userId = (int)$authUser['id'];

try {
    $pdo->beginTransaction();
    $bookingCreationLock = null;

    $checkUser = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = ?
          AND school_id = ?
          AND active = 1
    ");
    $checkUser->execute([$userId, $schoolId]);
    if (!$checkUser->fetch()) {
        throw new DomainException("Usuário inválido para esta escola.", 404);
    }

    $checkResource = $pdo->prepare("
        SELECT id
        FROM resources
        WHERE id = ?
          AND school_id = ?
          AND active = 1
    ");
    $checkResource->execute([$resourceId, $schoolId]);
    if (!$checkResource->fetch()) {
        throw new DomainException("Recurso inválido para esta escola.", 404);
    }

    $bookingCreationLock = acquireBookingCreationLock(
        $pdo,
        $schoolId,
        $resourceId,
        $bookingDate
    );
    if ($bookingCreationLock === false) {
        throw new DomainException(
            "Não foi possível processar o agendamento agora. Tente novamente em instantes.",
            503
        );
    }

    $checkClassGroup = $pdo->prepare("
        SELECT id
        FROM class_groups
        WHERE id = ?
          AND school_id = ?
          AND active = 1
    ");
    $checkClassGroup->execute([$classGroupId, $schoolId]);
    if (!$checkClassGroup->fetch()) {
        throw new DomainException("Turma inválida para esta escola.", 404);
    }

    $checkSubject = $pdo->prepare("
        SELECT id
        FROM subjects
        WHERE id = ?
          AND school_id = ?
          AND active = 1
    ");
    $checkSubject->execute([$subjectId, $schoolId]);
    if (!$checkSubject->fetch()) {
        throw new DomainException("Disciplina inválida para esta escola.", 404);
    }

    $lessonPlaceholders = implode(',', array_fill(0, count($lessonIds), '?'));

    $lessonCheckSql = "
        SELECT id
        FROM lesson_slots
        WHERE school_id = ?
          AND active = 1
          AND id IN ($lessonPlaceholders)
    ";
    $lessonCheckStmt = $pdo->prepare($lessonCheckSql);
    $lessonCheckStmt->execute(array_merge([$schoolId], $lessonIds));
    $validLessons = $lessonCheckStmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($validLessons) !== count($lessonIds)) {
        throw new DomainException("Uma ou mais aulas selecionadas são inválidas.", 400);
    }

    $hasIdempotencyKeyColumn = $idempotencyKey !== '' && databaseColumnExists($pdo, 'bookings', 'idempotency_key');
    if ($hasIdempotencyKeyColumn) {
        $idempotencyStmt = $pdo->prepare("
            SELECT id
            FROM bookings
            WHERE school_id = ?
              AND user_id = ?
              AND idempotency_key = ?
            LIMIT 1
        ");
        $idempotencyStmt->execute([$schoolId, $userId, $idempotencyKey]);
        $existingBookingId = $idempotencyStmt->fetchColumn();

        if ($existingBookingId !== false) {
            $pdo->commit();
            releaseBookingCreationLock($pdo, $bookingCreationLock);

            jsonResponse(true, "Agendamento já processado anteriormente.", [
                "booking_id" => (int) $existingBookingId,
                "resource_id" => $resourceId,
                "booking_date" => $bookingDate,
                "lesson_ids" => $lessonIds,
            ], 200);
        }
    }

    $conflicts = fetchBookingConflictsForLessons(
        $pdo,
        $schoolId,
        $resourceId,
        $bookingDate,
        $lessonIds
    );

    if (!empty($conflicts)) {
        $pdo->rollBack();
        releaseBookingCreationLock($pdo, $bookingCreationLock);
        respondWithBookingConflict($resourceId, $bookingDate, $conflicts);
    }

    $bookingColumns = [
        'school_id',
        'resource_id',
        'user_id',
        'class_group_id',
        'subject_id',
        'booking_date',
        'purpose',
        'status',
    ];
    $bookingValuePlaceholders = ['?', '?', '?', '?', '?', '?', '?', "'scheduled'"];

    if ($hasIdempotencyKeyColumn) {
        $bookingColumns[] = 'idempotency_key';
        $bookingValuePlaceholders[] = '?';
    }

    $bookingStmt = $pdo->prepare("
        INSERT INTO bookings (" . implode(', ', $bookingColumns) . ")
        VALUES (" . implode(', ', $bookingValuePlaceholders) . ")
    ");
    $bookingInsertParams = [
        $schoolId,
        $resourceId,
        $userId,
        $classGroupId,
        $subjectId,
        $bookingDate,
        $purpose
    ];
    if ($hasIdempotencyKeyColumn) {
        $bookingInsertParams[] = $idempotencyKey;
    }
    $bookingStmt->execute($bookingInsertParams);

    $bookingId = $pdo->lastInsertId();

    $bookingLessonStmt = $pdo->prepare("
        INSERT INTO booking_lessons (booking_id, lesson_slot_id)
        VALUES (?, ?)
    ");

    foreach ($lessonIds as $lessonId) {
        $bookingLessonStmt->execute([$bookingId, $lessonId]);
    }

    $pdo->commit();
    releaseBookingCreationLock($pdo, $bookingCreationLock);

    try {
        notifyTechniciansAboutBookingEvent(
            $pdo,
            (int) $schoolId,
            (int) $bookingId,
            'booking_created',
            (int) $userId
        );
    } catch (Throwable $notificationError) {
        error_log('Create booking notification failed: ' . $notificationError->getMessage());
    }

    jsonResponse(true, "Agendamento criado com sucesso.", [
        "booking_id" => (int) $bookingId,
        "resource_id" => $resourceId,
        "booking_date" => $bookingDate,
        "lesson_ids" => $lessonIds,
    ], 201);

} catch (DomainException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($bookingCreationLock)) {
        releaseBookingCreationLock($pdo, $bookingCreationLock);
    }

    $errorCode = match ((int) $e->getCode()) {
        409 => 'BOOKING_CONFLICT',
        404 => 'BOOKING_REFERENCE_NOT_FOUND',
        503 => 'BOOKING_LOCK_TIMEOUT',
        default => 'BOOKING_VALIDATION_ERROR',
    };

    jsonErrorResponse($e->getMessage(), $e->getCode() ?: 400, $errorCode);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($bookingCreationLock)) {
        releaseBookingCreationLock($pdo, $bookingCreationLock);
    }

    $hasIdempotencyKeyColumn = $idempotencyKey !== '' && databaseColumnExists($pdo, 'bookings', 'idempotency_key');
    $normalizedErrorMessage = strtolower($e->getMessage());
    $errorCode = (string) ($e->getCode() ?? '');
    if (
        $hasIdempotencyKeyColumn &&
        (
            str_contains($normalizedErrorMessage, 'idempotency') ||
            $errorCode === '23505' ||
            $errorCode === '23000'
        )
    ) {
        $existingBookingStmt = $pdo->prepare("
            SELECT id
            FROM bookings
            WHERE school_id = ?
              AND user_id = ?
              AND idempotency_key = ?
            LIMIT 1
        ");
        $existingBookingStmt->execute([$schoolId, $userId, $idempotencyKey]);
        $existingBookingId = $existingBookingStmt->fetchColumn();

        if ($existingBookingId !== false) {
            jsonResponse(true, "Agendamento já processado anteriormente.", [
                "booking_id" => (int) $existingBookingId,
                "resource_id" => $resourceId,
                "booking_date" => $bookingDate,
                "lesson_ids" => $lessonIds,
            ], 200);
        }
    }

    if ($errorCode === 'P0001' && str_contains($normalizedErrorMessage, 'booking_conflict')) {
        $conflicts = fetchBookingConflictsForLessons(
            $pdo,
            $schoolId,
            $resourceId,
            $bookingDate,
            $lessonIds
        );
        if (!empty($conflicts)) {
            respondWithBookingConflict($resourceId, $bookingDate, $conflicts);
        }
    }

    serverErrorResponse("Erro ao criar agendamento.");
}
