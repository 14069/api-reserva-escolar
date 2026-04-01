<?php
require_once 'response.php';

requireRequestMethod(['GET', 'HEAD']);

jsonResponse(true, 'healthy', [
    'status' => 'ok',
]);
