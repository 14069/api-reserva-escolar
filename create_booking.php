<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
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
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if (!isValidDateString($bookingDate)) {
    jsonResponse(false, "booking_date inválida. Use YYYY-MM-DD.", null, 400);
}

$lessonIds = array_values(array_unique(array_map('intval', $lessonIds)));
if (count($lessonIds) === 0 || in_array(0, $lessonIds, true)) {
    jsonResponse(false, "Uma ou mais aulas selecionadas são inválidas.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId);
$userId = (int)$authUser['id'];

try {
    $pdo->beginTransaction();

    $checkUser = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id = ?
          AND school_id = ?
          AND active = 1
    ");
    $checkUser->execute([$userId, $schoolId]);
    if (!$checkUser->fetch()) {
        throw new RuntimeException("Usuário inválido para esta escola.", 404);
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
        throw new RuntimeException("Recurso inválido para esta escola.", 404);
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
        throw new RuntimeException("Turma inválida para esta escola.", 404);
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
        throw new RuntimeException("Disciplina inválida para esta escola.", 404);
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
        throw new RuntimeException("Uma ou mais aulas selecionadas são inválidas.", 400);
    }

    $conflictSql = "
        SELECT ls.label
        FROM booking_lessons bl
        INNER JOIN bookings b ON b.id = bl.booking_id
        INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
        WHERE b.school_id = ?
          AND b.resource_id = ?
          AND b.booking_date = ?
          AND b.status = 'scheduled'
          AND bl.lesson_slot_id IN ($lessonPlaceholders)
        LIMIT 1
    ";
    $conflictStmt = $pdo->prepare($conflictSql);
    $conflictStmt->execute(array_merge([$schoolId, $resourceId, $bookingDate], $lessonIds));
    $conflict = $conflictStmt->fetch(PDO::FETCH_ASSOC);

    if ($conflict) {
        throw new RuntimeException(
            "Conflito de agendamento na aula: " . $conflict['label'],
            409
        );
    }

    $bookingStmt = $pdo->prepare("
        INSERT INTO bookings (
            school_id,
            resource_id,
            user_id,
            class_group_id,
            subject_id,
            booking_date,
            purpose,
            status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
    ");
    $bookingStmt->execute([
        $schoolId,
        $resourceId,
        $userId,
        $classGroupId,
        $subjectId,
        $bookingDate,
        $purpose
    ]);

    $bookingId = $pdo->lastInsertId();

    $bookingLessonStmt = $pdo->prepare("
        INSERT INTO booking_lessons (booking_id, lesson_slot_id)
        VALUES (?, ?)
    ");

    foreach ($lessonIds as $lessonId) {
        $bookingLessonStmt->execute([$bookingId, $lessonId]);
    }

    notifyTechniciansAboutBookingEvent(
        $pdo,
        (int) $schoolId,
        (int) $bookingId,
        'booking_created',
        (int) $userId
    );

    $pdo->commit();

    jsonResponse(true, "Agendamento criado com sucesso.", [
        "booking_id" => $bookingId
    ], 201);

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    jsonResponse(false, $e->getMessage(), null, $e->getCode() ?: 400);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    serverErrorResponse("Erro ao criar agendamento.");
}
