<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/utils.php';

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Fetch issues from database
            $repo_id = $_GET['repo_id'] ?? null;
            $page = intval($_GET['page'] ?? 1);
            $per_page = intval($_GET['per_page'] ?? 50);
            $check_updates = isset($_GET['check_updates']);
            $last_timestamp = $_GET['last_timestamp'] ?? null;

            if (!$repo_id) {
                http_response_code(400);
                echo json_encode(['error' => 'repo_id parameter required']);
                break;
            }

            // Get last updated timestamp
            $timestamp_result = db_get_one(
                "SELECT MAX(updated_at) as last_updated FROM issues WHERE repo_id = ?",
                [$repo_id]
            );
            $last_updated = $timestamp_result ? $timestamp_result['last_updated'] : null;

            // If checking for updates and no changes, return early
            if ($check_updates && $last_timestamp && $last_timestamp === $last_updated) {
                echo json_encode([
                    'status' => 'ok',
                    'last_updated' => $last_updated,
                    'has_updates' => false
                ]);
                break;
            }

            // Calculate offset
            $offset = ($page - 1) * $per_page;

            // Get total count
            $count_result = db_get_one(
                "SELECT COUNT(*) as total FROM issues WHERE repo_id = ?",
                [$repo_id]
            );
            $total = $count_result ? $count_result['total'] : 0;

            // Get issues
            $issues = db_get_all(
                "SELECT * FROM issues
                WHERE repo_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?",
                [$repo_id, $per_page, $offset]
            );

            // Parse JSON fields
            foreach ($issues as &$issue) {
                $issue['labels'] = json_decode($issue['labels'] ?? '[]', true);
            }

            echo json_encode([
                'status' => 'ok',
                'issues' => $issues,
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => ceil($total / $per_page),
                'last_updated' => $last_updated,
                'has_updates' => true
            ]);
            break;

        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            // Placeholder for different actions
            switch ($action) {
                case 'fetch_issues':
                    $repo_id = $input['repo_id'] ?? null;

                    if (!$repo_id) {
                        http_response_code(400);
                        echo json_encode(['error' => 'repo_id required']);
                        break;
                    }

                    // Get repository details
                    $repo = db_get_one(
                        "SELECT * FROM repos WHERE id = ?",
                        [$repo_id]
                    );

                    if (!$repo) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Repository not found']);
                        break;
                    }

                    // Fetch issues based on source
                    if ($repo['source'] === 'github') {
                        // Include GitHub library
                        require_once __DIR__ . '/../lib/github.php';

                        // Get GitHub token
                        $github_token = get_env_value('GITHUB_TOKEN');
                        if (empty($github_token)) {
                            http_response_code(400);
                            echo json_encode(['error' => 'GitHub token not configured']);
                            break;
                        }

                        try {
                            // Fetch issues from GitHub
                            $issues = github_fetch_issues($github_token, $repo['source_id']);

                            $new_count = 0;
                            $updated_count = 0;

                            // Start transaction
                            $db = get_db();
                            $db->exec('BEGIN');

                            foreach ($issues as $issue) {
                                $is_new = upsert_issue($repo_id, 'github', $issue);
                                if ($is_new) {
                                    $new_count++;
                                } else {
                                    $updated_count++;
                                }
                            }

                            // Commit transaction
                            $db->exec('COMMIT');

                            echo json_encode([
                                'status' => 'ok',
                                'new' => $new_count,
                                'updated' => $updated_count,
                                'total' => count($issues)
                            ]);

                        } catch (Exception $e) {
                            // Rollback on error
                            $db->exec('ROLLBACK');
                            http_response_code(500);
                            echo json_encode(['error' => 'Failed to fetch issues: ' . $e->getMessage()]);
                        }
                    } elseif ($repo['source'] === 'linear') {
                        // Include Linear library
                        require_once __DIR__ . '/../lib/linear.php';

                        // Get Linear token
                        $linear_token = get_env_value('LINEAR_TOKEN');
                        if (empty($linear_token)) {
                            http_response_code(400);
                            echo json_encode(['error' => 'Linear token not configured']);
                            break;
                        }

                        try {
                            // Fetch issues from Linear
                            $issues = linear_fetch_issues($linear_token, $repo['source_id']);

                            $new_count = 0;
                            $updated_count = 0;

                            // Start transaction
                            $db = get_db();
                            $db->exec('BEGIN');

                            foreach ($issues as $issue) {
                                $is_new = upsert_issue($repo_id, 'linear', $issue);
                                if ($is_new) {
                                    $new_count++;
                                } else {
                                    $updated_count++;
                                }
                            }

                            // Commit transaction
                            $db->exec('COMMIT');

                            echo json_encode([
                                'status' => 'ok',
                                'new' => $new_count,
                                'updated' => $updated_count,
                                'total' => count($issues)
                            ]);

                        } catch (Exception $e) {
                            // Rollback on error
                            if (isset($db)) {
                                $db->exec('ROLLBACK');
                            }
                            http_response_code(500);
                            echo json_encode(['error' => 'Failed to fetch Linear issues: ' . $e->getMessage()]);
                        }
                    } else {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid repository source']);
                    }
                    break;

                case 'check_prs':
                    $repo_id = $input['repo_id'] ?? null;

                    if (!$repo_id) {
                        http_response_code(400);
                        echo json_encode(['error' => 'repo_id required']);
                        break;
                    }

                    // Get repository details
                    $repo = db_get_one(
                        "SELECT * FROM repos WHERE id = ?",
                        [$repo_id]
                    );

                    if (!$repo) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Repository not found']);
                        break;
                    }

                    // Get GitHub token for PR detection (works for both GitHub and Linear issues)
                    $github_token = get_env_value('GITHUB_TOKEN');
                    if (empty($github_token)) {
                        echo json_encode([
                            'status' => 'ok',
                            'updated' => 0,
                            'message' => 'GitHub token not configured'
                        ]);
                        break;
                    }

                    // Get all issues with branches but no PR yet
                    $issues_with_branches = db_get_all(
                        "SELECT * FROM issues
                         WHERE repo_id = ?
                         AND pr_branch IS NOT NULL
                         AND pr_branch != ''
                         AND (pr_status = 'in_progress' OR pr_status = 'branch_pushed')",
                        [$repo_id]
                    );

                    $updated_count = 0;

                    // For GitHub repos, check for PRs
                    if ($repo['source'] === 'github' && !empty($repo['source_id'])) {
                        $updated_count += check_and_update_prs($github_token, $repo['source_id'], $issues_with_branches);
                    }

                    // For Linear issues, also check the configured GitHub repo
                    if ($repo['source'] === 'linear' && !empty($repo['github_repo'])) {
                        $updated_count += check_and_update_prs($github_token, $repo['github_repo'], $issues_with_branches);
                    }

                    echo json_encode([
                        'status' => 'ok',
                        'updated' => $updated_count,
                        'checked' => count($issues_with_branches)
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