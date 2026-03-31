<?php
require_once 'response.php';
require_once 'db.php';

$searchLikeOperator = getSearchLikeOperator();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$onlyActive = $_GET['only_active'] ?? '1';
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$category = trim($_GET['category'] ?? '');
$sort = trim($_GET['sort'] ?? 'name_asc');
$shouldPaginate = isset($_GET['page']) || isset($_GET['page_size']);
$pagination = $shouldPaginate ? getPaginationParams() : null;

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser(
    $pdo,
    $schoolId,
    $onlyActive === '0' ? 'technician' : null
);

$whereSql = "
    FROM resources r
    INNER JOIN resource_categories rc ON rc.id = r.category_id
    WHERE r.school_id = ?
";
$params = [$schoolId];

if ($onlyActive !== '0') {
    $whereSql .= " AND r.active = 1";
}

if ($status === 'active') {
    $whereSql .= " AND r.active = 1";
} elseif ($status === 'inactive') {
    $whereSql .= " AND r.active <> 1";
}

if ($category !== '') {
    $whereSql .= " AND rc.name = ?";
    $params[] = $category;
}

if ($search !== '') {
    $whereSql .= " AND (r.name $searchLikeOperator ? OR rc.name $searchLikeOperator ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$orderSql = " ORDER BY r.name ASC";
if ($sort === 'name_desc') {
    $orderSql = " ORDER BY r.name DESC";
} elseif ($sort === 'category_asc') {
    $orderSql = " ORDER BY rc.name ASC, r.name ASC";
} elseif ($sort === 'status') {
    $orderSql = " ORDER BY r.active DESC, r.name ASC";
}

$baseSelectSql = "
    SELECT
        r.id,
        r.name,
        r.active,
        rc.id AS category_id,
        rc.name AS category_name
    $whereSql
    $orderSql
";

if ($shouldPaginate) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) $whereSql");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN r.active = 1 THEN 1 ELSE 0 END) AS active_count
        $whereSql
    ");
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare($baseSelectSql . " LIMIT ? OFFSET ?");
    $queryParams = [...$params, $pagination['page_size'], $pagination['offset']];
} else {
    $stmt = $pdo->prepare($baseSelectSql);
    $queryParams = $params;
}

$stmt->execute($queryParams);

$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($shouldPaginate) {
    jsonResponse(
        true,
        "Recursos listados com sucesso.",
        $resources,
        200,
        buildPaginationMeta(
            $total,
            $pagination['page'],
            $pagination['page_size'],
            [
                'active_count' => (int) ($summaryRow['active_count'] ?? 0),
            ]
        )
    );
}

jsonResponse(true, "Recursos listados com sucesso.", $resources);
