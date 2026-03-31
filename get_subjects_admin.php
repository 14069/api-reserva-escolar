<?php
require_once 'response.php';
require_once 'db.php';

$searchLikeOperator = getSearchLikeOperator();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$sort = trim($_GET['sort'] ?? 'name_asc');
$pagination = getPaginationParams();

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');

$whereSql = "
    FROM subjects
    WHERE school_id = ?
";
$params = [$schoolId];

if ($status === 'active') {
    $whereSql .= " AND active = 1";
} elseif ($status === 'inactive') {
    $whereSql .= " AND active <> 1";
}

if ($search !== '') {
    $whereSql .= " AND name $searchLikeOperator ?";
    $params[] = '%' . $search . '%';
}

$orderSql = " ORDER BY name ASC";
if ($sort === 'name_desc') {
    $orderSql = " ORDER BY name DESC";
} elseif ($sort === 'status') {
    $orderSql = " ORDER BY active DESC, name ASC";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN active <> 1 THEN 1 ELSE 0 END) AS inactive_count
    $whereSql
");
$summaryStmt->execute($params);
$summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->prepare("
    SELECT id, school_id, name, active, created_at
    $whereSql
    $orderSql
    LIMIT ?
    OFFSET ?
");
$queryParams = [...$params, $pagination['page_size'], $pagination['offset']];
$stmt->execute($queryParams);

$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(
    true,
    "Disciplinas listadas com sucesso.",
    $subjects,
    200,
    buildPaginationMeta(
        $total,
        $pagination['page'],
        $pagination['page_size'],
        [
            'active_count' => (int) ($summaryRow['active_count'] ?? 0),
            'inactive_count' => (int) ($summaryRow['inactive_count'] ?? 0),
        ]
    )
);
