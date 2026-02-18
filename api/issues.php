<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/utils.php';

/**
 * Terminate processes associated with a callback ID.
 * Returns the number of matching processes detected before termination.
 */
function terminate_callback_processes($callback_id) {
    if (empty($callback_id)) {
        return 0;
    }

    $escaped = escapeshellarg($callback_id);
    $count = (int)trim((string)shell_exec("pgrep -f $escaped 2>/dev/null | wc -l | tr -d ' '"));

    // Try graceful termination first, then force kill.
    shell_exec("pkill -TERM -f $escaped 2>/dev/null");
    usleep(300000);
    shell_exec("pkill -KILL -f $escaped 2>/dev/null");

    return $count;
}

/**
 * Best-effort worktree cleanup for cancelled runs.
 */
function remove_worktree($repo_root_path, $worktree_path) {
    if (empty($repo_root_path) || empty($worktree_path) || !is_dir($worktree_path)) {
        return;
    }

    $repoArg = escapeshellarg($repo_root_path);
    $worktreeArg = escapeshellarg($worktree_path);
    shell_exec("git -C $repoArg worktree remove --force $worktreeArg 2>/dev/null");
}

/**
 * Get or initialize incremental sync state for a repository.
 */
function get_repo_sync_state($repo_id) {
    $state = db_get_one("SELECT * FROM repo_sync_state WHERE repo_id = ?", [$repo_id]);
    if (!$state) {
        db_query(
            "INSERT INTO repo_sync_state (repo_id, next_page, page_size, has_more, last_fetch_count, updated_at)
             VALUES (?, 1, 50, 1, 0, CURRENT_TIMESTAMP)",
            [$repo_id]
        );
        $state = db_get_one("SELECT * FROM repo_sync_state WHERE repo_id = ?", [$repo_id]);
    }

    if (!$state) {
        return [
            'repo_id' => $repo_id,
            'next_page' => 1,
            'next_cursor' => null,
            'page_size' => 50,
            'has_more' => 1,
            'last_fetch_count' => 0
        ];
    }

    return $state;
}

/**
 * Persist incremental sync state.
 */
function save_repo_sync_state($repo_id, $next_page, $next_cursor, $page_size, $has_more, $last_fetch_count) {
    db_query(
        "INSERT INTO repo_sync_state (repo_id, next_page, next_cursor, page_size, has_more, last_fetch_count, last_fetch_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON CONFLICT(repo_id) DO UPDATE SET
            next_page = excluded.next_page,
            next_cursor = excluded.next_cursor,
            page_size = excluded.page_size,
            has_more = excluded.has_more,
            last_fetch_count = excluded.last_fetch_count,
            last_fetch_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP",
        [$repo_id, $next_page, $next_cursor, $page_size, $has_more ? 1 : 0, $last_fetch_count]
    );
}

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
            $sync_state = get_repo_sync_state($repo_id);

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
                'has_more' => !empty($sync_state['has_more']),
                'next_page' => intval($sync_state['next_page'] ?? 1),
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

                    $sync_state = get_repo_sync_state($repo_id);
                    $page_size = intval($sync_state['page_size'] ?? 50);
                    if ($page_size < 1) {
                        $page_size = 50;
                    }

                    if (empty($sync_state['has_more'])) {
                        echo json_encode([
                            'status' => 'ok',
                            'new' => 0,
                            'updated' => 0,
                            'total' => 0,
                            'has_more' => false,
                            'message' => 'No more issues to fetch'
                        ]);
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
                            $next_page = intval($sync_state['next_page'] ?? 1);
                            if ($next_page < 1) {
                                $next_page = 1;
                            }

                            // Fetch one page from GitHub.
                            $fetch_result = github_fetch_issues_page($github_token, $repo['source_id'], $page_size, $next_page);
                            $issues = $fetch_result['issues'] ?? [];
                            $has_next = !empty($fetch_result['has_next']);

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

                            $next_page_to_store = $has_next ? ($next_page + 1) : $next_page;
                            save_repo_sync_state($repo_id, $next_page_to_store, null, $page_size, $has_next, count($issues));

                            echo json_encode([
                                'status' => 'ok',
                                'new' => $new_count,
                                'updated' => $updated_count,
                                'total' => count($issues),
                                'fetched_count' => count($issues),
                                'has_more' => $has_next,
                                'next_page' => $next_page_to_store
                            ]);

                        } catch (Exception $e) {
                            // Rollback on error
                            if (isset($db)) {
                                $db->exec('ROLLBACK');
                            }
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
                            $next_page = intval($sync_state['next_page'] ?? 1);
                            if ($next_page < 1) {
                                $next_page = 1;
                            }
                            $cursor = $sync_state['next_cursor'] ?? null;

                            // Fetch one page from Linear.
                            $fetch_result = linear_fetch_issues_page($linear_token, $repo['source_id'], $page_size, $cursor);
                            $issues = $fetch_result['issues'] ?? [];
                            $has_next = !empty($fetch_result['has_next']);
                            $next_cursor = $fetch_result['end_cursor'] ?? null;

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

                            $next_page_to_store = $has_next ? ($next_page + 1) : $next_page;
                            save_repo_sync_state($repo_id, $next_page_to_store, $next_cursor, $page_size, $has_next, count($issues));

                            echo json_encode([
                                'status' => 'ok',
                                'new' => $new_count,
                                'updated' => $updated_count,
                                'total' => count($issues),
                                'fetched_count' => count($issues),
                                'has_more' => $has_next,
                                'next_page' => $next_page_to_store
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

                    // Determine the GitHub repository to check
                    $github_repo_to_check = null;

                    if ($repo['source'] === 'github') {
                        // For GitHub repos, use the source_id directly
                        $github_repo_to_check = $repo['source_id'];
                    } else if ($repo['source'] === 'linear') {
                        // For Linear repos, detect GitHub repo from local git repo
                        if (!empty($repo['local_path']) && is_dir($repo['local_path'] . '/.git')) {
                            // Try to get GitHub repo from git remote
                            $cmd = sprintf(
                                "cd %s && git remote get-url origin 2>/dev/null | sed -E 's/.*github.com[:/](.+)(\\.git)?$/\\1/' | sed 's/\\.git$//'",
                                escapeshellarg($repo['local_path'])
                            );
                            $github_repo_from_git = trim(shell_exec($cmd));

                            if (!empty($github_repo_from_git) && strpos($github_repo_from_git, '/') !== false) {
                                $github_repo_to_check = $github_repo_from_git;
                            }
                        }
                    }

                    // Check for PRs if we have a GitHub repo
                    if (!empty($github_repo_to_check)) {
                        $updated_count += check_and_update_prs($github_token, $github_repo_to_check, $issues_with_branches);
                    }

                    echo json_encode([
                        'status' => 'ok',
                        'updated' => $updated_count,
                        'checked' => count($issues_with_branches)
                    ]);
                    break;

                case 'cancel_pr':
                    $issue_id = $input['issue_id'] ?? null;

                    if (!$issue_id) {
                        http_response_code(400);
                        echo json_encode(['error' => 'issue_id required']);
                        break;
                    }

                    $issue = db_get_one("SELECT id, pr_status FROM issues WHERE id = ?", [$issue_id]);
                    if (!$issue) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Issue not found']);
                        break;
                    }

                    // Gather pending callbacks before marking them cancelled.
                    $pending_callbacks = db_get_all(
                        "SELECT callback_id, worktree_path, repo_root_path
                         FROM callbacks
                         WHERE issue_id = ?
                         AND status = 'pending'",
                        [$issue_id]
                    );

                    $terminated_processes = 0;
                    foreach ($pending_callbacks as $callback) {
                        $terminated_processes += terminate_callback_processes($callback['callback_id'] ?? '');
                        remove_worktree($callback['repo_root_path'] ?? '', $callback['worktree_path'] ?? '');
                    }

                    // Mark callbacks as cancelled so late callbacks cannot overwrite state.
                    db_query(
                        "UPDATE callbacks
                         SET status = 'cancelled',
                             completed_at = CURRENT_TIMESTAMP
                         WHERE issue_id = ?
                         AND status = 'pending'",
                        [$issue_id]
                    );

                    // Reset issue PR state.
                    db_query(
                        "UPDATE issues
                         SET pr_status = 'none',
                             pr_url = NULL,
                             pr_branch = NULL,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?",
                        [$issue_id]
                    );

                    echo json_encode([
                        'status' => 'ok',
                        'message' => 'Run cancelled',
                        'terminated_processes' => $terminated_processes
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
