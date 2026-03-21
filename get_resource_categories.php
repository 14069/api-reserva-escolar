<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$stmt = $pdo->query("
    SELECT id, name
    FROM resource_categories
    ORDER BY name ASC
");

$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse(true, "Categorias listadas com sucesso.", $categories);