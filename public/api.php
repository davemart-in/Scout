<?php

// API Router
// Routes requests to the appropriate API endpoint

// Get the requested endpoint from the URL
$request_uri = $_SERVER['REQUEST_URI'];
$path_parts = parse_url($request_uri);
$path = $path_parts['path'] ?? '';

// Remove /api/ prefix
$endpoint = str_replace('/api/', '', $path);
$endpoint = str_replace('/api', '', $endpoint);
$endpoint = trim($endpoint, '/');

// Map endpoints to files
$api_files = [
    'settings' => __DIR__ . '/../api/settings.php',
    'issues' => __DIR__ . '/../api/issues.php',
    'analyze' => __DIR__ . '/../api/analyze.php',
    'launch' => __DIR__ . '/../api/launch.php',
    'callback' => __DIR__ . '/../api/callback.php',
];

// Route to the appropriate file
if (isset($api_files[$endpoint])) {
    require $api_files[$endpoint];
} else {
    header('Content-Type: application/json');
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found']);
}