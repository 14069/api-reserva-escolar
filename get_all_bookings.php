<?php
require_once 'response.php';
require_once 'db.php';

$formattedBookingDateExpression = getFormattedDateSearchExpression('b.booking_date');
$completedAtDateExpression = getDateOnlyExpression('b.completed_at');
$currentDateExpression = getCurrentDateExpression();
$weekdayIndexExpression = getWeekdayIndexExpression('b.booking_date');
$completionFeedbackSelect = databaseColumnExists($pdo, 'bookings', 'completion_feedback')
    ? 'b.completion_feedback'
    : 'NULL AS completion_feedback';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

$schoolId = $_GET['school_id'] ?? null;
$bookingDate = trim($_GET['booking_date'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');
$teacher = trim($_GET['teacher'] ?? '');
$resource = trim($_GET['resource'] ?? '');
$classGroup = trim($_GET['class_group'] ?? '');
$sort = trim($_GET['sort'] ?? 'date_desc');
$shouldPaginate = isset($_GET['page']) || isset($_GET['page_size']);
$pagination = $shouldPaginate ? getPaginationParams() : null;

if (empty($schoolId)) {
    jsonResponse(false, "O parâmetro school_id é obrigatório.", null, 400);
}

$authUser = requireAuthenticatedUser($pdo, $schoolId, 'technician');

$selectSql = "
    SELECT
        b.id,
        b.booking_date,
        b.purpose,
        b.status,
        b.cancelled_at,
        b.completed_at,
        $completionFeedbackSelect,
        r.name AS resource_name,
        u.name AS user_name,
        uc.name AS completed_by_name,
        cg.name AS class_group_name,
        s.name AS subject_name
";

$fromSql = "
    FROM bookings b
    INNER JOIN resources r ON r.id = b.resource_id
    INNER JOIN users u ON u.id = b.user_id
    LEFT JOIN users uc ON uc.id = b.completed_by_user_id
    INNER JOIN class_groups cg ON cg.id = b.class_group_id
    INNER JOIN subjects s ON s.id = b.subject_id
    WHERE b.school_id = ?
";

$params = [$schoolId];

if ($bookingDate !== '') {
    $fromSql .= " AND b.booking_date = ?";
    $params[] = $bookingDate;
}

if ($dateFrom !== '') {
    $fromSql .= " AND b.booking_date >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $fromSql .= " AND b.booking_date <= ?";
    $params[] = $dateTo;
}

if ($status !== '') {
    $fromSql .= " AND b.status = ?";
    $params[] = $status;
}

if ($teacher !== '') {
    $fromSql .= " AND u.name = ?";
    $params[] = $teacher;
}

if ($resource !== '') {
    $fromSql .= " AND r.name = ?";
    $params[] = $resource;
}

if ($classGroup !== '') {
    $fromSql .= " AND cg.name = ?";
    $params[] = $classGroup;
}

if ($search !== '') {
    $fromSql .= " AND (
        r.name LIKE ?
        OR u.name LIKE ?
        OR cg.name LIKE ?
        OR s.name LIKE ?
        OR b.purpose LIKE ?
        OR $formattedBookingDateExpression LIKE ?
    )";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$orderSql = " ORDER BY b.booking_date DESC, b.id DESC";
if ($sort === 'date_asc') {
    $orderSql = " ORDER BY b.booking_date ASC, b.id ASC";
} elseif ($sort === 'teacher_asc') {
    $orderSql = " ORDER BY u.name ASC, b.booking_date DESC, b.id DESC";
} elseif ($sort === 'resource_asc') {
    $orderSql = " ORDER BY r.name ASC, b.booking_date DESC, b.id DESC";
}

$baseSelectSql = $selectSql . $fromSql . $orderSql;

if ($shouldPaginate) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) " . $fromSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $summaryStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN b.status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled_count,
            SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            SUM(CASE WHEN b.status = 'completed' AND $completedAtDateExpression = $currentDateExpression THEN 1 ELSE 0 END) AS completed_today_count,
            COUNT(DISTINCT u.id) AS unique_teachers_count,
            COUNT(DISTINCT r.id) AS unique_resources_count,
            COUNT(DISTINCT cg.id) AS unique_class_groups_count,
            COUNT(DISTINCT s.id) AS unique_subjects_count
        " . $fromSql);
    $summaryStmt->execute($params);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $overallCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM bookings
        WHERE school_id = ?
    ");
    $overallCountStmt->execute([$schoolId]);
    $overallCount = (int) $overallCountStmt->fetchColumn();

    $lessonSummaryFromSql = str_replace(
        "FROM bookings b\n    INNER JOIN resources r ON r.id = b.resource_id",
        "FROM bookings b\n    LEFT JOIN booking_lessons bl ON bl.booking_id = b.id\n    INNER JOIN resources r ON r.id = b.resource_id",
        $fromSql
    );

    $lessonSummaryStmt = $pdo->prepare("
        SELECT COUNT(bl.lesson_slot_id) AS total_reserved_lessons
        " . $lessonSummaryFromSql);
    $lessonSummaryStmt->execute($params);
    $lessonSummaryRow = $lessonSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalReservedLessons = (int) ($lessonSummaryRow['total_reserved_lessons'] ?? 0);

    $weekdayStmt = $pdo->prepare("
        SELECT
            $weekdayIndexExpression AS weekday_index,
            COUNT(*) AS total
        " . $fromSql . "
        GROUP BY weekday_index
        ORDER BY total DESC, weekday_index ASC
        LIMIT 1
    ");
    $weekdayStmt->execute($params);
    $weekdayRow = $weekdayStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $weekdayLabels = [
        '0' => 'Segunda-feira',
        '1' => 'Terça-feira',
        '2' => 'Quarta-feira',
        '3' => 'Quinta-feira',
        '4' => 'Sexta-feira',
        '5' => 'Sábado',
        '6' => 'Domingo',
    ];
    $weekdayKey = (string) ($weekdayRow['weekday_index'] ?? '');
    $busiestWeekdayLabel = $weekdayLabels[$weekdayKey] ?? 'Sem dados';

    $buildRanking = function (string $labelExpression) use ($pdo, $fromSql, $params) {
        $rankingStmt = $pdo->prepare("
            SELECT
                " . $labelExpression . " AS label,
                COUNT(*) AS value
            " . $fromSql . "
            GROUP BY label
            HAVING label <> ''
            ORDER BY value DESC, label ASC
            LIMIT 5
        ");
        $rankingStmt->execute($params);

        return array_map(function ($row) {
            return [
                'label' => (string) ($row['label'] ?? ''),
                'value' => (int) ($row['value'] ?? 0),
            ];
        }, $rankingStmt->fetchAll(PDO::FETCH_ASSOC));
    };

    $optionsStmt = $pdo->prepare("
        SELECT DISTINCT
            u.name AS user_name,
            r.name AS resource_name,
            cg.name AS class_group_name,
            b.status
        " . $fromSql . "
        ORDER BY b.booking_date DESC, b.id DESC
    ");
    $optionsStmt->execute($params);
    $optionRows = $optionsStmt->fetchAll(PDO::FETCH_ASSOC);

    $teacherOptions = [];
    $resourceOptions = [];
    $classGroupOptions = [];
    $statusOptions = [];

    foreach ($optionRows as $row) {
        $teacherName = trim((string) ($row['user_name'] ?? ''));
        $resourceName = trim((string) ($row['resource_name'] ?? ''));
        $classGroupName = trim((string) ($row['class_group_name'] ?? ''));
        $statusName = trim((string) ($row['status'] ?? ''));

        if ($teacherName !== '') {
            $teacherOptions[$teacherName] = true;
        }
        if ($resourceName !== '') {
            $resourceOptions[$resourceName] = true;
        }
        if ($classGroupName !== '') {
            $classGroupOptions[$classGroupName] = true;
        }
        if ($statusName !== '') {
            $statusOptions[$statusName] = true;
        }
    }

    $teacherOptions = array_keys($teacherOptions);
    $resourceOptions = array_keys($resourceOptions);
    $classGroupOptions = array_keys($classGroupOptions);
    $statusOptions = array_keys($statusOptions);

    sort($teacherOptions, SORT_NATURAL | SORT_FLAG_CASE);
    sort($resourceOptions, SORT_NATURAL | SORT_FLAG_CASE);
    sort($classGroupOptions, SORT_NATURAL | SORT_FLAG_CASE);
    sort($statusOptions, SORT_NATURAL | SORT_FLAG_CASE);

    $stmt = $pdo->prepare($baseSelectSql . " LIMIT ? OFFSET ?");
    $queryParams = [...$params, $pagination['page_size'], $pagination['offset']];
} else {
    $stmt = $pdo->prepare($baseSelectSql);
    $queryParams = $params;
}

$stmt->execute($queryParams);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$lessonsStmt = $pdo->prepare("
    SELECT
        ls.id,
        ls.lesson_number,
        ls.label
    FROM booking_lessons bl
    INNER JOIN lesson_slots ls ON ls.id = bl.lesson_slot_id
    WHERE bl.booking_id = ?
    ORDER BY ls.lesson_number ASC
");

foreach ($bookings as &$booking) {
    $lessonsStmt->execute([$booking['id']]);
    $booking['lessons'] = $lessonsStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($shouldPaginate) {
    jsonResponse(
        true,
        "Todos os agendamentos listados com sucesso.",
        $bookings,
        200,
        buildPaginationMeta(
            $total,
            $pagination['page'],
            $pagination['page_size'],
            [
                'scheduled_count' => (int) ($summaryRow['scheduled_count'] ?? 0),
                'completed_count' => (int) ($summaryRow['completed_count'] ?? 0),
                'cancelled_count' => (int) ($summaryRow['cancelled_count'] ?? 0),
                'completed_today_count' => (int) ($summaryRow['completed_today_count'] ?? 0),
                'overall_count' => $overallCount,
                'unique_teachers_count' => (int) ($summaryRow['unique_teachers_count'] ?? 0),
                'unique_resources_count' => (int) ($summaryRow['unique_resources_count'] ?? 0),
                'unique_class_groups_count' => (int) ($summaryRow['unique_class_groups_count'] ?? 0),
                'unique_subjects_count' => (int) ($summaryRow['unique_subjects_count'] ?? 0),
                'total_reserved_lessons' => $totalReservedLessons,
                'average_lessons_per_booking' => $total > 0 ? round($totalReservedLessons / $total, 2) : 0,
                'busiest_weekday_label' => $busiestWeekdayLabel,
                'teacher_options' => $teacherOptions,
                'resource_options' => $resourceOptions,
                'class_group_options' => $classGroupOptions,
                'status_options' => $statusOptions,
                'teacher_ranking' => $buildRanking('u.name'),
                'resource_ranking' => $buildRanking('r.name'),
                'class_group_ranking' => $buildRanking('cg.name'),
                'subject_ranking' => $buildRanking('s.name'),
            ]
        )
    );
}

jsonResponse(true, "Todos os agendamentos listados com sucesso.", $bookings);
