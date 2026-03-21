<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');

$stmt = $pdo->prepare("
    SELECT id, school_id, name, email, role, active, created_at
    FROM users
    WHERE school_id = ?
      AND role = 'teacher'
    ORDER BY name ASC
");
$stmt->execute([$schoolId]);

$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true, "Professores listados com sucesso.", $teachers);
