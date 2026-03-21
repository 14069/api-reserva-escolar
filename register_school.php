<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$input = getJsonInput();

$schoolName = trim($input['school_name'] ?? '');
$schoolCode = trim($input['school_code'] ?? '');
$schoolPassword = trim($input['school_password'] ?? '');

$technicianName = trim($input['technician_name'] ?? '');
$technicianEmail = trim($input['technician_email'] ?? '');
$technicianPassword = trim($input['technician_password'] ?? '');

$chromebooksCount = (int)($input['chromebooks_count'] ?? 0);
$audiovisualCount = (int)($input['audiovisual_count'] ?? 0);
$spacesCount = (int)($input['spaces_count'] ?? 0);

$classGroups = $input['class_groups'] ?? [];
$subjects = $input['subjects'] ?? [];
$lessonCount = (int)($input['lesson_count'] ?? 0);

if (
    empty($schoolName) ||
    empty($schoolCode) ||
    empty($schoolPassword) ||
    empty($technicianName) ||
    empty($technicianEmail) ||
    empty($technicianPassword)
) {
    jsonResponse(false, "Dados obrigatórios não informados.", null, 400);
}

if ($lessonCount <= 0) {
    jsonResponse(false, "A quantidade de aulas deve ser maior que zero.", null, 400);
}

if (!isValidEmailAddress($technicianEmail)) {
    jsonResponse(false, "Email do técnico inválido.", null, 400);
}

if (strlen($schoolPassword) < 6 || strlen($technicianPassword) < 6) {
    jsonResponse(false, "As senhas devem ter ao menos 6 caracteres.", null, 400);
}

$checkSchool = $pdo->prepare("SELECT id FROM schools WHERE school_code = ?");
$checkSchool->execute([$schoolCode]);

if ($checkSchool->fetch()) {
    jsonResponse(false, "Já existe uma escola com esse código.", null, 409);
}

try {
    $pdo->beginTransaction();

    $schoolStmt = $pdo->prepare("
        INSERT INTO schools (school_name, school_code, password)
        VALUES (?, ?, ?)
    ");
    $schoolStmt->execute([
        $schoolName,
        $schoolCode,
        password_hash($schoolPassword, PASSWORD_DEFAULT)
    ]);

    $schoolId = $pdo->lastInsertId();

    $userStmt = $pdo->prepare("
        INSERT INTO users (school_id, name, email, password, role)
        VALUES (?, ?, ?, ?, 'technician')
    ");
    $userStmt->execute([
        $schoolId,
        $technicianName,
        $technicianEmail,
        password_hash($technicianPassword, PASSWORD_DEFAULT)
    ]);

    $categoryStmt = $pdo->query("SELECT id, name FROM resource_categories");
    $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryMap = [];
    foreach ($categories as $category) {
        $categoryMap[$category['name']] = $category['id'];
    }

    $resourceInsert = $pdo->prepare("
        INSERT INTO resources (school_id, category_id, name, active)
        VALUES (?, ?, ?, 1)
    ");

    for ($i = 1; $i <= $chromebooksCount; $i++) {
        $resourceInsert->execute([
            $schoolId,
            $categoryMap['chromebooks'],
            "Carrinho de Chromebooks $i"
        ]);
    }

    for ($i = 1; $i <= $audiovisualCount; $i++) {
        $resourceInsert->execute([
            $schoolId,
            $categoryMap['audiovisual'],
            "Recurso Audiovisual $i"
        ]);
    }

    for ($i = 1; $i <= $spacesCount; $i++) {
        $resourceInsert->execute([
            $schoolId,
            $categoryMap['espacos'],
            "Espaço $i"
        ]);
    }

    $classStmt = $pdo->prepare("
        INSERT INTO class_groups (school_id, name, active)
        VALUES (?, ?, 1)
    ");

    foreach ($classGroups as $group) {
        $groupName = trim($group);
        if (!empty($groupName)) {
            $classStmt->execute([$schoolId, $groupName]);
        }
    }

    $subjectStmt = $pdo->prepare("
        INSERT INTO subjects (school_id, name, active)
        VALUES (?, ?, 1)
    ");

    foreach ($subjects as $subject) {
        $subjectName = trim($subject);
        if (!empty($subjectName)) {
            $subjectStmt->execute([$schoolId, $subjectName]);
        }
    }

    $lessonStmt = $pdo->prepare("
        INSERT INTO lesson_slots (school_id, lesson_number, label, active)
        VALUES (?, ?, ?, 1)
    ");

    for ($i = 1; $i <= $lessonCount; $i++) {
        $label = $i . 'ª aula';
        $lessonStmt->execute([$schoolId, $i, $label]);
    }

    $pdo->commit();

    jsonResponse(true, "Escola cadastrada com sucesso.", [
        "school_id" => $schoolId,
        "school_name" => $schoolName,
        "school_code" => $schoolCode
    ], 201);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    serverErrorResponse("Erro ao cadastrar escola.");
}
