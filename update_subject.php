<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolId = $input['school_id'] ?? null;
$userId = $input['user_id'] ?? null;
$subjectId = $input['subject_id'] ?? null;
$name = trim($input['name'] ?? '');

if (empty($schoolId) || empty($userId) || empty($subjectId) || empty($name)) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
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
    jsonResponse(false, "Usuário sem permissão para editar disciplinas.", null, 403);
}

$checkSubject = $pdo->prepare("
    SELECT id
    FROM subjects
    WHERE id = ?
      AND school_id = ?
");
$checkSubject->execute([$subjectId, $schoolId]);

if (!$checkSubject->fetch()) {
    jsonResponse(false, "Disciplina não encontrada.", null, 404);
}

$checkName = $pdo->prepare("
    SELECT id
    FROM subjects
    WHERE school_id = ?
      AND name = ?
      AND id <> ?
");
$checkName->execute([$schoolId, $name, $subjectId]);

if ($checkName->fetch()) {
    jsonResponse(false, "Já existe outra disciplina com esse nome nesta escola.", null, 409);
}

$stmt = $pdo->prepare("
    UPDATE subjects
    SET name = ?
    WHERE id = ? AND school_id = ?
");
$stmt->execute([$name, $subjectId, $schoolId]);

jsonResponse(true, "Disciplina atualizada com sucesso.");
