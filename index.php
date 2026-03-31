<?php
require_once 'response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(false, 'Método não permitido.', null, 405);
}

jsonResponse(true, 'Reserva Escolar API V2 online.', [
    'service' => 'reserva_escolar_api',
    'status' => 'ok',
]);
