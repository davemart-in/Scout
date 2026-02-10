<?php

// Set JSON response header
header('Content-Type: application/json');

// Include database library
require_once __DIR__ . '/../lib/db.php';

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $issue_id = $input['issue_id'] ?? null;
            $model = $input['model'] ?? null;

            // Placeholder response
            echo json_encode([
                'status' => 'ok',
                'message' => 'launch not yet implemented',
                'callback_id' => ''
            ]);
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'error' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}