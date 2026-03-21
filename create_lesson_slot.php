<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$lessonNumber = $input['lesson_number'] ?? null;
$label = trim($input['label'] ?? '');
$startTime = trim($input['start_time'] ?? '');
$endTime = trim($input['end_time'] ?? '');

if (empty($schoolId) || empty($userId) || empty($lessonNumber) || empty($label)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if ((int)$lessonNumber <= 0) {
    jsonResponse(false, "Número da aula inválido.", null, 400);
}

if ($startTime !== '' && !isValidTimeString($startTime)) {
    jsonResponse(false, "Hora inicial inválida. Use HH:MM:SS.", null, 400);
}

if ($endTime !== '' && !isValidTimeString($endTime)) {
    jsonResponse(false, "Hora final inválida. Use HH:MM:SS.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');
$userId = (int)$authUser['id'];

$checkUser = $pdo->prepare("
    SELECT id
    FROM users
    WHERE id = ?
      AND school_id = ?
      AND role = 'technician'
      AND active = 1
");
$checkUser->execute([$userId, $schoolId]);

if (!$checkUser->fetch()) {
    jsonResponse(false, "Usuário sem permissão para cadastrar aulas.", null, 403);
}

$checkNumber = $pdo->prepare("
    SELECT id
    FROM lesson_slots
    WHERE school_id = ?
      AND lesson_number = ?
");
$checkNumber->execute([$schoolId, $lessonNumber]);

if ($checkNumber->fetch()) {
    jsonResponse(false, "Já existe uma aula com esse número nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    INSERT INTO lesson_slots (school_id, lesson_number, label, start_time, end_time, active)
    VALUES (?, ?, ?, ?, ?, 1)
");
$stmt->execute([
    $schoolId,
    $lessonNumber,
    $label,
    $startTime !== '' ? $startTime : null,
    $endTime !== '' ? $endTime : null,
]);

jsonResponse(true, "Aula cadastrada com sucesso.", [
    "lesson_slot_id" => $pdo->lastInsertId()
], 201);
