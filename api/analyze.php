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
            $action = $input['action'] ?? '';

            // Placeholder for different actions
            switch ($action) {
                case 'analyze_batch':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'analyze_batch not yet implemented',
                        'analyzed' => 0,
                        'remaining' => 0,
                        'results' => []
                    ]);
                    break;

                case 'analyze_single':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'analyze_single not yet implemented'
                    ]);
                    break;

                default:
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Invalid action'
                    ]);
                    break;
            }
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