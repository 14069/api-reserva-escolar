<?php

require_once __DIR__ . '/bootstrap_env.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';

try {
    $result = $pdo->query("
        SELECT
            current_database() AS database_name,
            current_user AS current_user,
            version() AS version_text
    ")->fetch(PDO::FETCH_ASSOC);

    jsonResponse(true, 'Conexão com Supabase verificada com sucesso.', $result);
} catch (Throwable $error) {
    jsonResponse(false, 'Falha ao consultar o Supabase.', [
        'error' => $error->getMessage(),
    ], 500);
}
