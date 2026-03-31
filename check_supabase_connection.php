<?php

require_once __DIR__ . '/bootstrap_env.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Método não permitido.', null, 405);
}

requireDiagnosticAccess();

try {
    $result = $pdo->query("SELECT 1 AS connection_ok")->fetch(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Conexão com o banco verificada com sucesso.', [
        'status' => ((int) ($result['connection_ok'] ?? 0)) === 1 ? 'ok' : 'unknown',
        'driver' => getDatabaseDriver(),
    ]);
} catch (Throwable $error) {
    error_log('Database diagnostic check failed: ' . $error->getMessage());
    serverErrorResponse('Falha ao verificar conexão com o banco de dados.');
}
