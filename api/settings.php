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
            // Placeholder for fetching settings
            echo json_encode([
                'status' => 'ok',
                'message' => 'settings GET not yet implemented',
                'tokens' => [],
                'repos' => [],
                'has_github' => false,
                'has_linear' => false,
                'has_openai' => false,
                'has_anthropic' => false
            ]);
            break;

        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            // Placeholder for different actions
            switch ($action) {
                case 'save_token':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'save_token not yet implemented'
                    ]);
                    break;

                case 'save_repo':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'save_repo not yet implemented'
                    ]);
                    break;

                case 'delete_token':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'delete_token not yet implemented'
                    ]);
                    break;

                case 'add_repo':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'add_repo not yet implemented'
                    ]);
                    break;

                case 'delete_repo':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'delete_repo not yet implemented'
                    ]);
                    break;

                case 'fetch_repos':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'fetch_repos not yet implemented'
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