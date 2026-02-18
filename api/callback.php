<?php

// Set JSON response header
header('Content-Type: application/json');

// Include database library
require_once __DIR__ . '/../lib/db.php';

/**
 * Best-effort worktree cleanup for completed/cancelled runs.
 */
function remove_worktree($repo_root_path, $worktree_path) {
    if (empty($repo_root_path) || empty($worktree_path) || !is_dir($worktree_path)) {
        return;
    }

    $repoArg = escapeshellarg($repo_root_path);
    $worktreeArg = escapeshellarg($worktree_path);
    shell_exec("git -C $repoArg worktree remove --force $worktreeArg 2>/dev/null");
}

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Get parameters
            $callback_id = $_GET['id'] ?? null;
            $status = $_GET['status'] ?? null;
            $pr_url = $_GET['pr_url'] ?? null;
            $error = $_GET['error'] ?? null;

            if (!$callback_id) {
                http_response_code(400);
                echo json_encode(['error' => 'callback_id is required']);
                break;
            }

            if (!$status) {
                http_response_code(400);
                echo json_encode(['error' => 'status is required']);
                break;
            }

            // Look up the callback record
            $callback = db_get_one(
                "SELECT c.*, i.repo_id, r.auto_create_pr
                 FROM callbacks c
                 JOIN issues i ON c.issue_id = i.id
                 JOIN repos r ON i.repo_id = r.id
                 WHERE c.callback_id = ?",
                [$callback_id]
            );

            if (!$callback) {
                http_response_code(404);
                echo json_encode(['error' => 'Callback not found']);
                break;
            }

            // Ignore duplicate or late callbacks idempotently.
            if ($callback['status'] === 'cancelled') {
                remove_worktree($callback['repo_root_path'] ?? '', $callback['worktree_path'] ?? '');
                echo json_encode([
                    'status' => 'ok',
                    'ignored' => true,
                    'message' => 'Callback ignored because run was cancelled'
                ]);
                break;
            }
            if ($callback['status'] !== 'pending') {
                echo json_encode([
                    'status' => 'ok',
                    'ignored' => true,
                    'message' => 'Callback already processed'
                ]);
                break;
            }

            // Determine PR status based on callback status
            $pr_status = 'none';
            if ($status === 'complete') {
                if ($callback['auto_create_pr']) {
                    // If PR was supposed to be created
                    $pr_status = $pr_url ? 'pr_created' : 'needs_review';
                } else {
                    // Just branch push was requested
                    $pr_status = 'branch_pushed';
                }
            } elseif ($status === 'failed') {
                $pr_status = 'failed';
            } elseif ($status === 'needs_review') {
                $pr_status = 'needs_review';
            }

            // Update the issue
            if ($pr_url) {
                db_query(
                    "UPDATE issues
                     SET pr_status = ?,
                         pr_url = ?,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?",
                    [$pr_status, $pr_url, $callback['issue_id']]
                );
            } else {
                db_query(
                    "UPDATE issues
                     SET pr_status = ?,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = ?",
                    [$pr_status, $callback['issue_id']]
                );
            }

            // Update callback record
            db_query(
                "UPDATE callbacks
                 SET status = ?,
                     completed_at = CURRENT_TIMESTAMP
                 WHERE callback_id = ?",
                [$status, $callback_id]
            );

            remove_worktree($callback['repo_root_path'] ?? '', $callback['worktree_path'] ?? '');

            // Log any error details if provided
            if ($error) {
                error_log("Claude Code callback error for $callback_id: $error");
            }

            // Return success
            echo json_encode([
                'status' => 'ok',
                'pr_status' => $pr_status,
                'message' => 'Callback processed successfully'
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
