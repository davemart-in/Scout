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
                                // Check if issue exists
                                $existing = db_get_one(
                                    "SELECT id, assessment, pr_status FROM issues
                                    WHERE source = 'github' AND source_id = ? AND repo_id = ?",
                                    [$issue['source_id'], $repo_id]
                                );

                                // Prepare labels as JSON
                                $labels_json = json_encode($issue['labels']);

                                if ($existing) {
                                    // Update existing issue, preserving assessment and pr_status
                                    $result = db_query(
                                        "UPDATE issues SET
                                            title = ?,
                                            description = ?,
                                            labels = ?,
                                            source_url = ?,
                                            status = ?,
                                            updated_at = CURRENT_TIMESTAMP
                                        WHERE id = ?",
                                        [
                                            $issue['title'],
                                            $issue['description'],
                                            $labels_json,
                                            $issue['source_url'],
                                            $issue['status'],
                                            $existing['id']
                                        ]
                                    );
                                    $updated_count++;
                                } else {
                                    // Insert new issue
                                    $result = db_query(
                                        "INSERT INTO issues (
                                            repo_id, source, source_id, source_url,
                                            title, description, labels, priority,
                                            status, assessment, created_at
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                                        [
                                            $repo_id,
                                            'github',
                                            $issue['source_id'],
                                            $issue['source_url'],
                                            $issue['title'],
                                            $issue['description'],
                                            $labels_json,
                                            $issue['priority'],
                                            $issue['status'],
                                            $issue['created_at']
                                        ]
                                    );
                                    $new_count++;
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
                                // Check if issue exists
                                $existing = db_get_one(
                                    "SELECT id, assessment, pr_status FROM issues
                                    WHERE source = 'linear' AND source_id = ? AND repo_id = ?",
                                    [$issue['source_id'], $repo_id]
                                );

                                // Prepare labels as JSON
                                $labels_json = json_encode($issue['labels']);

                                if ($existing) {
                                    // Update existing issue, preserving assessment and pr_status
                                    $result = db_query(
                                        "UPDATE issues SET
                                            title = ?,
                                            description = ?,
                                            labels = ?,
                                            priority = ?,
                                            source_url = ?,
                                            status = ?,
                                            updated_at = CURRENT_TIMESTAMP
                                        WHERE id = ?",
                                        [
                                            $issue['title'],
                                            $issue['description'],
                                            $labels_json,
                                            $issue['priority'],
                                            $issue['source_url'],
                                            $issue['status'],
                                            $existing['id']
                                        ]
                                    );
                                    $updated_count++;
                                } else {
                                    // Insert new issue
                                    $result = db_query(
                                        "INSERT INTO issues (
                                            repo_id, source, source_id, source_url,
                                            title, description, labels, priority,
                                            status, assessment, created_at
                                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
                                        [
                                            $repo_id,
                                            'linear',
                                            $issue['source_id'],
                                            $issue['source_url'],
                                            $issue['title'],
                                            $issue['description'],
                                            $labels_json,
                                            $issue['priority'],
                                            $issue['status'],
                                            $issue['created_at']
                                        ]
                                    );
                                    $new_count++;
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

                    // Include GitHub library
                    require_once __DIR__ . '/../lib/github.php';

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
                        try {
                            // Fetch all open PRs for the repository
                            $prs = github_get_pulls($github_token, $repo['source_id']);

                            // Check each issue's branch against PRs
                            foreach ($issues_with_branches as $issue) {
                                $branch_name = $issue['pr_branch'];

                                // Look for a PR from this branch
                                foreach ($prs as $pr) {
                                    if (isset($pr['head']['ref']) && $pr['head']['ref'] === $branch_name) {
                                        // Found a PR for this branch
                                        $pr_url = $pr['html_url'] ?? '';
                                        $pr_status = $pr['draft'] ? 'needs_review' : 'pr_created';

                                        // Update issue with PR info
                                        db_query(
                                            "UPDATE issues
                                             SET pr_url = ?, pr_status = ?, updated_at = CURRENT_TIMESTAMP
                                             WHERE id = ?",
                                            [$pr_url, $pr_status, $issue['id']]
                                        );
                                        $updated_count++;
                                        break;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // Log error but don't fail completely
                            error_log('PR detection error: ' . $e->getMessage());
                        }
                    }

                    // For Linear issues, also check the configured GitHub repo
                    if ($repo['source'] === 'linear' && !empty($repo['github_repo'])) {
                        try {
                            // Use the github_repo field for Linear repos
                            $prs = github_get_pulls($github_token, $repo['github_repo']);

                            foreach ($issues_with_branches as $issue) {
                                $branch_name = $issue['pr_branch'];

                                foreach ($prs as $pr) {
                                    if (isset($pr['head']['ref']) && $pr['head']['ref'] === $branch_name) {
                                        $pr_url = $pr['html_url'] ?? '';
                                        $pr_status = $pr['draft'] ? 'needs_review' : 'pr_created';

                                        db_query(
                                            "UPDATE issues
                                             SET pr_url = ?, pr_status = ?, updated_at = CURRENT_TIMESTAMP
                                             WHERE id = ?",
                                            [$pr_url, $pr_status, $issue['id']]
                                        );
                                        $updated_count++;
                                        break;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log('PR detection error for Linear: ' . $e->getMessage());
                        }
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