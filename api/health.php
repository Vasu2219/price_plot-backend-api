<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'status' => 'ok',
    'service' => 'price-plot-backend-api'
]);
