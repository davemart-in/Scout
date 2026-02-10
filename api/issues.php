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
            // Placeholder for fetching issues
            $repo_id = $_GET['repo_id'] ?? null;
            $check_updates = $_GET['check_updates'] ?? null;
            $page = $_GET['page'] ?? 1;
            $per_page = $_GET['per_page'] ?? 50;

            echo json_encode([
                'status' => 'ok',
                'message' => 'issues GET not yet implemented',
                'issues' => [],
                'last_updated' => date('c'),
                'page' => $page,
                'per_page' => $per_page,
                'total' => 0
            ]);
            break;

        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            // Placeholder for different actions
            switch ($action) {
                case 'fetch_issues':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'fetch_issues not yet implemented',
                        'new' => 0,
                        'updated' => 0
                    ]);
                    break;

                case 'check_prs':
                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'check_prs not yet implemented'
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