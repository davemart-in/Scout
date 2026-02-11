<?php

/**
 * Router for PHP built-in development server
 * This handles routing for API endpoints and static files
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Handle API routes
if (strpos($path, '/api/') === 0) {
    // Remove /api/ prefix and get endpoint name
    $endpoint = str_replace('/api/', '', $path);
    $endpoint = trim($endpoint, '/');

    // Map to API files - check if endpoint already has .php extension
    if (substr($endpoint, -4) === '.php') {
        $api_file = __DIR__ . '/api/' . $endpoint;
    } else {
        $api_file = __DIR__ . '/api/' . $endpoint . '.php';
    }

    if (file_exists($api_file)) {
        require $api_file;
        return true;
    } else {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found']);
        return true;
    }
}

// Let PHP built-in server handle static files and index
return false;