<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$onlyActive = $_GET['only_active'] ?? '1';

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser(
    $pdo,
    $schoolId,
    $onlyActive === '0' ? 'technician' : null
);

$sql = "
    SELECT
        r.id,
        r.name,
        r.active,
        rc.id AS category_id,
        rc.name AS category_name
    FROM resources r
    INNER JOIN resource_categories rc ON rc.id = r.category_id
    WHERE r.school_id = ?
";

if ($onlyActive !== '0') {
    $sql .= " AND r.active = 1";
}

$sql .= " ORDER BY rc.name ASC, r.name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$schoolId]);

$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true, "Recursos listados com sucesso.", $resources);
