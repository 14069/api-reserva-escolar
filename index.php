<?php
require_once 'response.php';

requireRequestMethod(['GET', 'HEAD']);

jsonResponse(true, 'Reserva Escolar API V2 online.', [
    'service' => 'reserva_escolar_api',
    'status' => 'ok',
]);
