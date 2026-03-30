<?php
require_once 'response.php';
require_once 'db.php';
require_once 'notifications_utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, "Método não permitido.", null, 405);
}

requireCronAccess();

$result = sendBookingCompletionReminderNotifications($pdo);

jsonResponse(true, "Lembretes processados com sucesso.", $result);
