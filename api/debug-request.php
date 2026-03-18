<?php
// api/debug-request.php
// Diagnostic endpoint to check what the frontend is actually sending

require_once '../config/cors.php';

header('Content-Type: application/json');

$response = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set',
    'headers' => getallheaders(),
    'raw_input' => file_get_contents('php://input'),
    'parsed_input' => json_decode(file_get_contents('php://input'), true),
];

echo json_encode($response, JSON_PRETTY_PRINT);
