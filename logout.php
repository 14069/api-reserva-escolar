<?php
require_once 'response.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$authUser = requireAuthenticatedUser($pdo);

$stmt = $pdo->prepare("
    UPDATE users
    SET api_token = NULL,
        api_token_expires_at = NULL
    WHERE id = ?
");
$stmt->execute([$authUser['id']]);

jsonResponse(true, "Logout realizado com sucesso.");
