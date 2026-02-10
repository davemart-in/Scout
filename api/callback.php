<?php

// Set JSON response header
header('Content-Type: application/json');

// Include database library
require_once __DIR__ . '/../lib/db.php';

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get parameters
            $callback_id = $_GET['id'] ?? null;
            $status = $_GET['status'] ?? null;
            $pr_url = $_GET['pr_url'] ?? null;

            // Placeholder response
            echo json_encode([
                'status' => 'ok',
                'message' => 'callback not yet implemented'
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