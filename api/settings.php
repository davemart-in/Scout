<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/utils.php';


/**
 * Check which tokens/keys are configured in .env
 */
function get_token_status() {
    return [
        'has_github' => !empty(get_env_value('GITHUB_TOKEN')),
        'has_linear' => !empty(get_env_value('LINEAR_TOKEN')),
        'has_openai' => !empty(get_env_value('OPENAI_KEY')),
        'has_anthropic' => !empty(get_env_value('ANTHROPIC_KEY'))
    ];
}

/**
 * Get all repositories
 */
function get_all_repos() {
    return db_get_all("SELECT id, source, source_id, name, local_path, default_branch, auto_create_pr FROM repos ORDER BY name");
}

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get token status and repos
            $token_status = get_token_status();
            $repos = get_all_repos();

            // Convert auto_create_pr to boolean
            foreach ($repos as &$repo) {
                $repo['auto_create_pr'] = (bool)$repo['auto_create_pr'];
            }

            // Get model preferences
            $assessment_model = get_setting('assessment_model') ?: 'gpt-5.2';
            $pr_creation_model = get_setting('pr_creation_model') ?: 'claude-opus-4-6';

            // Get available models based on API keys
            $available_models = get_available_models();

            echo json_encode([
                'status' => 'ok',
                'repos' => $repos,
                'has_github' => $token_status['has_github'],
                'has_linear' => $token_status['has_linear'],
                'has_openai' => $token_status['has_openai'],
                'has_anthropic' => $token_status['has_anthropic'],
                'assessment_model' => $assessment_model,
                'pr_creation_model' => $pr_creation_model,
                'available_models' => $available_models
            ]);
            break;

        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            switch ($action) {
                case 'save_model_preferences':
                    $assessment_model = $input['assessment_model'] ?? '';
                    $pr_creation_model = $input['pr_creation_model'] ?? '';

                    // Save both preferences
                    $success = save_setting('assessment_model', $assessment_model) &&
                               save_setting('pr_creation_model', $pr_creation_model);

                    if ($success) {
                        echo json_encode([
                            'status' => 'ok',
                            'message' => 'Model preferences saved successfully'
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to save model preferences']);
                    }
                    break;

                case 'save_token':
                case 'delete_token':
                    // Tokens are now managed in .env file, not through API
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Tokens must be configured in the .env file',
                        'message' => 'Edit the .env file to add or update API tokens'
                    ]);
                    break;

                case 'add_repo':
                    $source = $input['source'] ?? '';
                    $source_id = $input['source_id'] ?? '';
                    $name = $input['name'] ?? '';
                    $local_path = $input['local_path'] ?? '';
                    $default_branch = $input['default_branch'] ?? 'main';
                    $auto_create_pr = $input['auto_create_pr'] ?? 0;

                    // Validate required fields
                    if (empty($source) || empty($source_id) || empty($name)) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Missing required fields']);
                        break;
                    }

                    // Validate source
                    if (!in_array($source, ['github', 'linear'])) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid source']);
                        break;
                    }

                    // Insert repo
                    $result = db_query(
                        "INSERT INTO repos (source, source_id, name, local_path, default_branch, auto_create_pr)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [$source, $source_id, $name, $local_path, $default_branch, $auto_create_pr ? 1 : 0]
                    );

                    if ($result) {
                        echo json_encode([
                            'status' => 'ok',
                            'message' => 'Repository added successfully',
                            'id' => $GLOBALS['db']->lastInsertId()
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to add repository']);
                    }
                    break;

                case 'save_repo':
                    $id = $input['id'] ?? null;
                    $local_path = $input['local_path'] ?? '';
                    $default_branch = $input['default_branch'] ?? 'main';
                    $auto_create_pr = $input['auto_create_pr'] ?? 0;

                    if (!$id) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Repository ID required']);
                        break;
                    }

                    // Update repo
                    $result = db_query(
                        "UPDATE repos SET local_path = ?, default_branch = ?, auto_create_pr = ? WHERE id = ?",
                        [$local_path, $default_branch, $auto_create_pr ? 1 : 0, $id]
                    );

                    if ($result) {
                        echo json_encode([
                            'status' => 'ok',
                            'message' => 'Repository updated successfully'
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to update repository']);
                    }
                    break;

                case 'delete_repo':
                    $id = $input['id'] ?? null;

                    if (!$id) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Repository ID required']);
                        break;
                    }

                    // Delete repo and associated issues
                    db_query("DELETE FROM issues WHERE repo_id = ?", [$id]);
                    $result = db_query("DELETE FROM repos WHERE id = ?", [$id]);

                    if ($result) {
                        echo json_encode([
                            'status' => 'ok',
                            'message' => 'Repository deleted successfully'
                        ]);
                    } else {
                        http_response_code(500);
                        echo json_encode(['error' => 'Failed to delete repository']);
                    }
                    break;

                case 'fetch_repos':
                    $source = $input['source'] ?? '';

                    if ($source === 'github') {
                        // Include GitHub library
                        require_once __DIR__ . '/../lib/github.php';

                        // Get GitHub token from environment
                        $github_token = get_env_value('GITHUB_TOKEN');
                        if (empty($github_token)) {
                            http_response_code(400);
                            echo json_encode(['error' => 'GitHub token not configured']);
                            break;
                        }

                        try {
                            $repos = github_list_repos($github_token);
                            echo json_encode([
                                'status' => 'ok',
                                'repos' => $repos
                            ]);
                        } catch (Exception $e) {
                            http_response_code(500);
                            echo json_encode(['error' => 'Failed to fetch GitHub repos: ' . $e->getMessage()]);
                        }
                    } elseif ($source === 'linear') {
                        // Include Linear library
                        require_once __DIR__ . '/../lib/linear.php';

                        // Get Linear token from environment
                        $linear_token = get_env_value('LINEAR_TOKEN');
                        if (empty($linear_token)) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Linear token not configured']);
                            break;
                        }

                        try {
                            $teams = linear_list_teams($linear_token);
                            echo json_encode([
                                'status' => 'ok',
                                'repos' => $teams // Actually teams, but using same field name for consistency
                            ]);
                        } catch (Exception $e) {
                            http_response_code(500);
                            echo json_encode(['error' => 'Failed to fetch Linear teams: ' . $e->getMessage()]);
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid source']);
                    }
                    break;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
                    break;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}