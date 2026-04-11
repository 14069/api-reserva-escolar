<?php

function shouldUseAdminListingVariant(): bool
{
    $adminQueryKeys = ['page', 'page_size', 'search', 'status', 'sort', 'category', 'summary_mode'];

    foreach ($adminQueryKeys as $key) {
        if (array_key_exists($key, $_GET)) {
            return true;
        }
    }

    return false;
}

function resolveApiScript(string $requestMethod, string $requestPath): ?string
{
    $normalizedMethod = strtoupper($requestMethod);
    $exactRoutes = [
        'GET' => [
            '/' => 'index.php',
            '/health' => 'health.php',
            '/resources' => 'get_resources.php',
            '/resources/categories' => 'get_resource_categories.php',
            '/teachers' => 'get_teachers.php',
            '/subjects' => shouldUseAdminListingVariant()
                ? 'get_subjects_admin.php'
                : 'get_subjects.php',
            '/class-groups' => shouldUseAdminListingVariant()
                ? 'get_class_groups_admin.php'
                : 'get_class_groups.php',
            '/lesson-slots' => 'get_lesson_slots_admin.php',
            '/available-lessons' => 'get_available_lessons.php',
            '/bookings' => 'get_all_bookings.php',
            '/my-bookings' => 'get_my_bookings.php',
            '/notifications' => 'get_notifications.php',
            '/notifications/unread-count' => 'get_notifications_unread_count.php',
            '/check-supabase-connection' => 'check_supabase_connection.php',
            '/send-booking-completion-reminders' => 'send_booking_completion_reminders.php',
        ],
        'HEAD' => [
            '/' => 'index.php',
            '/health' => 'health.php',
        ],
        'POST' => [
            '/login' => 'login.php',
            '/logout' => 'logout.php',
            '/schools/register' => 'register_school.php',
            '/account/change-password' => 'change_my_password.php',
            '/resources' => 'create_resource.php',
            '/teachers' => 'create_teacher.php',
            '/subjects' => 'create_subject.php',
            '/class-groups' => 'create_class_group.php',
            '/lesson-slots' => 'create_lesson_slot.php',
            '/bookings' => 'create_booking.php',
            '/bookings/cancel' => 'cancel_booking.php',
            '/bookings/complete' => 'complete_booking.php',
            '/notifications/read' => 'mark_notification_read.php',
            '/notifications/read-all' => 'mark_all_notifications_read.php',
            '/send-booking-completion-reminders' => 'send_booking_completion_reminders.php',
        ],
    ];

    if (isset($exactRoutes[$normalizedMethod][$requestPath])) {
        return $exactRoutes[$normalizedMethod][$requestPath];
    }

    $patternRoutes = [
        'POST' => [
            '#^/resources/\d+$#' => 'update_resource.php',
            '#^/resources/\d+/toggle-status$#' => 'toggle_resource_status.php',
            '#^/teachers/\d+$#' => 'update_teacher.php',
            '#^/teachers/\d+/toggle-status$#' => 'toggle_teacher_status.php',
            '#^/teachers/\d+/reset-password$#' => 'reset_teacher_password.php',
            '#^/subjects/\d+$#' => 'update_subject.php',
            '#^/subjects/\d+/toggle-status$#' => 'toggle_subject_status.php',
            '#^/class-groups/\d+$#' => 'update_class_group.php',
            '#^/class-groups/\d+/toggle-status$#' => 'toggle_class_group_status.php',
            '#^/lesson-slots/\d+$#' => 'update_lesson_slot.php',
            '#^/lesson-slots/\d+/toggle-status$#' => 'toggle_lesson_slot_status.php',
        ],
    ];

    foreach ($patternRoutes[$normalizedMethod] ?? [] as $pattern => $script) {
        if (preg_match($pattern, $requestPath) === 1) {
            return $script;
        }
    }

    return null;
}

$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = rawurldecode((string) (parse_url($requestUri, PHP_URL_PATH) ?? '/'));
$documentRoot = __DIR__;
$requestedFile = $documentRoot . $requestPath;

if ($requestPath !== '/' && is_file($requestedFile)) {
    return false;
}

$script = resolveApiScript((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $requestPath);
if ($script !== null) {
    require $documentRoot . '/' . $script;
    return true;
}

require_once $documentRoot . '/response.php';
jsonErrorResponse(
    'Rota não encontrada.',
    404,
    'ROUTE_NOT_FOUND',
    null,
    [
        'path' => $requestPath,
        'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    ]
);
