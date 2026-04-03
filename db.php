<?php
require_once __DIR__ . '/bootstrap_env.php';

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

function getDatabaseConnectionSettings() {
    $databaseUrl = trim((string) getDatabaseConfigValue('RESERVA_DB_URL', ''));
    if ($databaseUrl !== '') {
        $parts = parse_url($databaseUrl);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('RESERVA_DB_URL inválida.');
        }

        $settings = [
            'driver' => strtolower((string) $parts['scheme']),
            'host' => (string) $parts['host'],
            'port' => isset($parts['port']) ? (string) $parts['port'] : null,
            'name' => ltrim((string) ($parts['path'] ?? ''), '/'),
            'username' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
            'charset' => 'utf8mb4',
            'sslmode' => null,
        ];

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        if (!empty($queryParams['sslmode'])) {
            $settings['sslmode'] = (string) $queryParams['sslmode'];
        }

        if (in_array($settings['driver'], ['postgres', 'postgresql'], true)) {
            $settings['driver'] = 'pgsql';
        }

        return $settings;
    }

    return [
        'driver' => strtolower((string) getDatabaseConfigValue('RESERVA_DB_DRIVER', 'mysql')),
        'host' => getDatabaseConfigValue('RESERVA_DB_HOST', '127.0.0.1'),
        'port' => getDatabaseConfigValue('RESERVA_DB_PORT', '3306'),
        'name' => getDatabaseConfigValue('RESERVA_DB_NAME', 'reserva_escolar_v2'),
        'username' => getDatabaseConfigValue('RESERVA_DB_USERNAME', 'root'),
        'password' => getDatabaseConfigValue('RESERVA_DB_PASSWORD', ''),
        'charset' => getDatabaseConfigValue('RESERVA_DB_CHARSET', 'utf8mb4'),
        'sslmode' => getDatabaseConfigValue('RESERVA_DB_SSLMODE', null),
    ];
}

function getDatabaseDriver() {
    static $driver = null;

    if ($driver !== null) {
        return $driver;
    }

    $settings = getDatabaseConnectionSettings();
    $driver = $settings['driver'];

    return $driver;
}

function getFormattedDateSearchExpression($columnName) {
    return getDatabaseDriver() === 'pgsql'
        ? "TO_CHAR($columnName, 'DD/MM/YYYY')"
        : "DATE_FORMAT($columnName, '%d/%m/%Y')";
}

function getCurrentTimestampExpression() {
    return 'CURRENT_TIMESTAMP';
}

function getCurrentDateExpression() {
    return 'CURRENT_DATE';
}

function getDateOnlyExpression($columnName) {
    return "CAST($columnName AS DATE)";
}

function getSearchLikeOperator() {
    return getDatabaseDriver() === 'pgsql' ? 'ILIKE' : 'LIKE';
}

function getSearchableTextExpression($columnName) {
    return getDatabaseDriver() === 'pgsql'
        ? "CAST($columnName AS TEXT)"
        : "CAST($columnName AS CHAR)";
}

function getWeekdayIndexExpression($columnName) {
    return getDatabaseDriver() === 'pgsql'
        ? "((EXTRACT(ISODOW FROM $columnName))::int - 1)"
        : "WEEKDAY($columnName)";
}

function buildBookingCreationLockName(int $schoolId, int $resourceId, string $bookingDate): string {
    return sprintf('booking:%d:%d:%s', $schoolId, $resourceId, $bookingDate);
}

function normalizeSigned32BitFromString(string $value): int {
    $intValue = (int) $value;
    if ($intValue > 2147483647) {
        $intValue -= 4294967296;
    }

    return $intValue;
}

function acquireBookingCreationLock(PDO $pdo, int $schoolId, int $resourceId, string $bookingDate, int $timeoutSeconds = 10): string|null|false {
    $lockName = buildBookingCreationLockName($schoolId, $resourceId, $bookingDate);

    if (getDatabaseDriver() === 'pgsql') {
        $lockKey1 = $schoolId;
        $lockKey2 = normalizeSigned32BitFromString(sprintf('%u', crc32($lockName)));
        $deadlineAt = microtime(true) + max(1, $timeoutSeconds);

        do {
            $stmt = $pdo->prepare('SELECT pg_try_advisory_xact_lock(?, ?)');
            $stmt->execute([$lockKey1, $lockKey2]);
            if ($stmt->fetchColumn()) {
                return null;
            }

            usleep(200000);
        } while (microtime(true) < $deadlineAt);

        return false;
    }

    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$lockName, $timeoutSeconds]);
    $lockAcquired = $stmt->fetchColumn();

    return ((int) $lockAcquired === 1) ? $lockName : false;
}

function releaseBookingCreationLock(PDO $pdo, ?string $lockName): void {
    if ($lockName === null || $lockName === '' || getDatabaseDriver() === 'pgsql') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable $error) {
        error_log('Failed to release booking creation lock: ' . $error->getMessage());
    }
}

function databaseColumnExists(PDO $pdo, string $tableName, string $columnName): bool {
    static $cache = [];

    $cacheKey = getDatabaseDriver() . ':' . $tableName . ':' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (getDatabaseDriver() === 'pgsql') {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName, $columnName]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName, $columnName]);
    }

    $cache[$cacheKey] = (bool) $stmt->fetchColumn();

    return $cache[$cacheKey];
}

function databaseTableExists(PDO $pdo, string $tableName): bool {
    static $cache = [];

    $cacheKey = getDatabaseDriver() . ':' . $tableName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (getDatabaseDriver() === 'pgsql') {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = 'public'
              AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName]);
    } else {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName]);
    }

    $cache[$cacheKey] = (bool) $stmt->fetchColumn();

    return $cache[$cacheKey];
}

$settings = getDatabaseConnectionSettings();
$driver = $settings['driver'];
$host = $settings['host'];
$port = $settings['port'];
$dbname = $settings['name'];
$username = $settings['username'];
$password = $settings['password'];
$charset = $settings['charset'];
$sslmode = $settings['sslmode'];

try {
    if ($driver === 'pgsql') {
        $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
        if ($sslmode !== null && $sslmode !== '') {
            $dsn .= ";sslmode=$sslmode";
        }
    } else {
        $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    serverErrorResponse("Erro na conexão com o banco de dados.");
} catch (RuntimeException $e) {
    error_log('Database configuration failed: ' . $e->getMessage());
    serverErrorResponse("Erro na configuração do banco de dados.");
}
