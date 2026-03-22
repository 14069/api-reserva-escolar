<?php
function getDatabaseConfigValue($key, $default = null) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return $_SERVER[$key];
    }

    return $default;
}

$host = getDatabaseConfigValue('RESERVA_DB_HOST', '127.0.0.1');
$port = getDatabaseConfigValue('RESERVA_DB_PORT', '3306');
$dbname = getDatabaseConfigValue('RESERVA_DB_NAME', 'reserva_escolar_v2');
$username = getDatabaseConfigValue('RESERVA_DB_USERNAME', 'root');
$password = getDatabaseConfigValue('RESERVA_DB_PASSWORD', '');

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    serverErrorResponse("Erro na conexão com o banco de dados.");
}
