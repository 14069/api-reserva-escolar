<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap_env.php';

$mysqlDsn = getenv('MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=reserva_escolar_v2;charset=utf8mb4';
$mysqlUser = getenv('MYSQL_USER') ?: 'root';
$mysqlPassword = getenv('MYSQL_PASSWORD') ?: '';

$configuredDatabaseUrl = getenv('RESERVA_DB_URL') ?: '';
if ($configuredDatabaseUrl !== '' && strpos($configuredDatabaseUrl, 'sslmode=') === false) {
    $configuredDatabaseUrl .= (str_contains($configuredDatabaseUrl, '?') ? '&' : '?') . 'sslmode=require';
}

$pgDsn = getenv('PG_DSN') ?: $configuredDatabaseUrl ?: 'pgsql:host=127.0.0.1;port=54322;dbname=postgres;sslmode=disable';
$pgUser = getenv('PG_USER') ?: 'postgres';
$pgPassword = getenv('PG_PASSWORD') ?: 'postgres';

$tables = [
    'schools' => ['id', 'school_name', 'school_code', 'password', 'created_at'],
    'resource_categories' => ['id', 'name'],
    'users' => [
        'id',
        'school_id',
        'name',
        'email',
        'password',
        'api_token',
        'api_token_expires_at',
        'role',
        'active',
        'created_at',
    ],
    'class_groups' => ['id', 'school_id', 'name', 'active', 'created_at'],
    'subjects' => ['id', 'school_id', 'name', 'active', 'created_at'],
    'lesson_slots' => [
        'id',
        'school_id',
        'lesson_number',
        'label',
        'start_time',
        'end_time',
        'active',
        'created_at',
    ],
    'resources' => ['id', 'school_id', 'category_id', 'name', 'active', 'created_at'],
    'bookings' => [
        'id',
        'school_id',
        'resource_id',
        'user_id',
        'class_group_id',
        'subject_id',
        'booking_date',
        'purpose',
        'status',
        'created_at',
        'cancelled_at',
        'completed_at',
        'completion_feedback',
        'completed_by_user_id',
        'cancelled_by_user_id',
    ],
    'booking_lessons' => ['id', 'booking_id', 'lesson_slot_id'],
];

$sequenceTables = [
    'schools',
    'resource_categories',
    'users',
    'class_groups',
    'subjects',
    'lesson_slots',
    'resources',
    'bookings',
    'booking_lessons',
];

function connectPdo(string $dsn, string $user, string $password): PDO
{
    if (str_starts_with($dsn, 'postgres://') || str_starts_with($dsn, 'postgresql://')) {
        $parts = parse_url($dsn);
        if ($parts === false || empty($parts['host'])) {
            throw new RuntimeException('PG_DSN/RESERVA_DB_URL inválido.');
        }

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        $dsn = 'pgsql:host=' . $parts['host']
            . ';port=' . ($parts['port'] ?? 5432)
            . ';dbname=' . ltrim((string) ($parts['path'] ?? '/postgres'), '/');

        if (!empty($queryParams['sslmode'])) {
            $dsn .= ';sslmode=' . $queryParams['sslmode'];
        }

        $user = rawurldecode((string) ($parts['user'] ?? $user));
        $password = rawurldecode((string) ($parts['pass'] ?? $password));
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function quoteIdentifier(string $name): string
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function quoteMysqlIdentifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function getMysqlTableColumns(PDO $mysql, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $mysql->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$table]);

    $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return $cache[$table];
}

function resetSequences(PDO $pg, array $tables): void
{
    foreach ($tables as $table) {
        $sql = "
            SELECT setval(
                pg_get_serial_sequence('public.$table', 'id'),
                GREATEST((SELECT COALESCE(MAX(id), 1) FROM public.$table), 1),
                (SELECT COALESCE(MAX(id), 0) > 0 FROM public.$table)
            )
        ";
        $pg->query($sql);
    }
}

$mysql = connectPdo($mysqlDsn, $mysqlUser, $mysqlPassword);
$pg = connectPdo($pgDsn, $pgUser, $pgPassword);

echo "Conectado ao MySQL e ao PostgreSQL local.\n";

$truncateList = implode(', ', array_map(
    static fn(string $table): string => 'public.' . $table,
    array_reverse(array_keys($tables))
));
$pg->exec("TRUNCATE TABLE $truncateList RESTART IDENTITY CASCADE");

echo "Tabelas do PostgreSQL limpas.\n";

$pg->beginTransaction();

try {
    foreach ($tables as $table => $columns) {
        $mysqlColumns = getMysqlTableColumns($mysql, $table);
        $mysqlColumnSql = implode(', ', array_map(
            static function (string $column) use ($mysqlColumns): string {
                if (in_array($column, $mysqlColumns, true)) {
                    return quoteMysqlIdentifier($column);
                }

                return 'NULL AS ' . quoteMysqlIdentifier($column);
            },
            $columns
        ));
        $pgColumnSql = implode(', ', array_map(static fn(string $column): string => quoteIdentifier($column), $columns));
        $select = $mysql->query("SELECT $mysqlColumnSql FROM " . quoteMysqlIdentifier($table) . " ORDER BY id ASC");
        $rows = $select->fetchAll();

        if (count($rows) === 0) {
            echo "Tabela $table: 0 linhas.\n";
            continue;
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $insert = $pg->prepare(
            'INSERT INTO public.' . $table . ' (' . $pgColumnSql . ') VALUES (' . $placeholders . ')'
        );

        foreach ($rows as $row) {
            $insert->execute(array_map(
                static fn(string $column) => $row[$column],
                $columns
            ));
        }

        echo "Tabela $table: " . count($rows) . " linhas importadas.\n";
    }

    resetSequences($pg, $sequenceTables);
    $pg->commit();
    echo "Importacao concluida com sucesso.\n";
} catch (Throwable $error) {
    if ($pg->inTransaction()) {
        $pg->rollBack();
    }

    fwrite(STDERR, "Falha na importacao: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
