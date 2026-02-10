<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ai.php';

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';

            switch ($action) {
                case 'analyze_batch':
                    // Get parameters
                    $repoId = $input['repo_id'] ?? null;
                    $model = $input['model'] ?? null;

                    if (!$repoId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'repo_id is required']);
                        break;
                    }

                    // If no model specified, use the assessment model from settings
                    if (!$model) {
                        $model = get_setting('assessment_model');
                        if (!$model) {
                            $model = 'gpt-5.2'; // Default fallback
                        }
                    }

                    // Fetch up to 5 pending issues from this repo
                    $pendingIssues = db_get_all(
                        "SELECT * FROM issues
                         WHERE repo_id = ? AND assessment = 'pending'
                         LIMIT 5",
                        [$repoId]
                    );

                    $analyzed = 0;
                    $results = [];
                    $errors = [];

                    foreach ($pendingIssues as $issue) {
                        try {
                            // Analyze the issue
                            $analysis = ai_analyze_issue($issue, $model);

                            // Update the database
                            $success = db_query(
                                "UPDATE issues
                                 SET summary = ?,
                                     assessment = ?,
                                     analysis_model = ?,
                                     analyzed_at = CURRENT_TIMESTAMP,
                                     updated_at = CURRENT_TIMESTAMP
                                 WHERE id = ?",
                                [
                                    $analysis['summary'],
                                    $analysis['assessment'],
                                    $model,
                                    $issue['id']
                                ]
                            );

                            if ($success) {
                                $analyzed++;
                                $results[] = [
                                    'id' => $issue['id'],
                                    'title' => $issue['title'],
                                    'assessment' => $analysis['assessment'],
                                    'summary' => $analysis['summary']
                                ];
                            }

                        } catch (Exception $e) {
                            // Log error but continue with other issues
                            $errors[] = [
                                'issue_id' => $issue['id'],
                                'error' => $e->getMessage()
                            ];
                        }
                    }

                    // Count remaining pending issues
                    $remainingRow = db_get_one(
                        "SELECT COUNT(*) as count FROM issues
                         WHERE repo_id = ? AND assessment = 'pending'",
                        [$repoId]
                    );
                    $remaining = $remainingRow['count'] ?? 0;

                    // Prepare response
                    $response = [
                        'status' => 'ok',
                        'analyzed' => $analyzed,
                        'remaining' => $remaining,
                        'results' => $results
                    ];

                    if (!empty($errors)) {
                        $response['errors'] = $errors;
                    }

                    echo json_encode($response);
                    break;

                case 'analyze_single':
                    // Get parameters
                    $issueId = $input['issue_id'] ?? null;
                    $model = $input['model'] ?? null;

                    if (!$issueId) {
                        http_response_code(400);
                        echo json_encode(['error' => 'issue_id is required']);
                        break;
                    }

                    // If no model specified, use the assessment model from settings
                    if (!$model) {
                        $model = get_setting('assessment_model');
                        if (!$model) {
                            $model = 'gpt-5.2'; // Default fallback
                        }
                    }

                    // Fetch the issue
                    $issue = db_get_one("SELECT * FROM issues WHERE id = ?", [$issueId]);

                    if (!$issue) {
                        http_response_code(404);
                        echo json_encode(['error' => 'Issue not found']);
                        break;
                    }

                    try {
                        // Analyze the issue
                        $analysis = ai_analyze_issue($issue, $model);

                        // Update the database
                        $success = db_query(
                            "UPDATE issues
                             SET summary = ?,
                                 assessment = ?,
                                 analysis_model = ?,
                                 analyzed_at = CURRENT_TIMESTAMP,
                                 updated_at = CURRENT_TIMESTAMP
                             WHERE id = ?",
                            [
                                $analysis['summary'],
                                $analysis['assessment'],
                                $model,
                                $issueId
                            ]
                        );

                        if ($success) {
                            // Fetch updated issue
                            $updatedIssue = db_get_one("SELECT * FROM issues WHERE id = ?", [$issueId]);

                            echo json_encode([
                                'status' => 'ok',
                                'issue' => $updatedIssue
                            ]);
                        } else {
                            throw new Exception('Failed to update issue in database');
                        }

                    } catch (Exception $e) {
                        http_response_code(500);
                        echo json_encode([
                            'error' => 'Analysis failed: ' . $e->getMessage()
                        ]);
                    }
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