<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/utils.php';

// Template processing and slugify functions moved to lib/utils.php

try {
    // Route based on request method
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            $issue_id = $input['issue_id'] ?? null;
            $context = $input['context'] ?? '';

            if (!$issue_id) {
                http_response_code(400);
                echo json_encode(['error' => 'issue_id is required']);
                break;
            }

            // Fetch issue and repo from database
            $issue = db_get_one(
                "SELECT i.*, r.local_path, r.source, r.auto_create_pr, r.default_branch
                 FROM issues i
                 JOIN repos r ON i.repo_id = r.id
                 WHERE i.id = ?",
                [$issue_id]
            );

            if (!$issue) {
                http_response_code(404);
                echo json_encode(['error' => 'Issue not found']);
                break;
            }

            // Verify repo has local_path configured
            if (empty($issue['local_path'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Repository local path not configured']);
                break;
            }

            // Verify local path exists
            if (!is_dir($issue['local_path'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Repository local path does not exist: ' . $issue['local_path']]);
                break;
            }

            // Get model from settings
            $model = get_setting('pr_creation_model');
            if (!$model) {
                $model = 'claude-3-5-sonnet-20241022'; // Default fallback
            }

            // Get Claude CLI model name
            $cliModel = get_claude_model_mapping($model);

            // Generate unique callback ID
            $callback_id = uniqid('cb_');

            // Read PR creation template
            $template = file_get_contents(__DIR__ . '/../lib/prompts/pr-creation.txt');
            if (!$template) {
                throw new Exception('Could not read PR creation template');
            }

            // Format labels
            $labels = '';
            if (!empty($issue['labels'])) {
                if (is_string($issue['labels'])) {
                    $labelsArray = json_decode($issue['labels'], true);
                    if (is_array($labelsArray)) {
                        $labels = implode(', ', $labelsArray);
                    } else {
                        $labels = $issue['labels'];
                    }
                }
            }

            // Prepare template data
            $templateData = [
                'title' => $issue['title'],
                'description' => $issue['description'] ?? '',
                'labels' => $labels,
                'source_id' => $issue['source_id'],
                'slug' => slugify($issue['title']),
                'callback_id' => $callback_id,
                'context' => $context,
                'has_context' => !empty($context),
                'auto_pr' => (bool)$issue['auto_create_pr'],
                'is_github' => $issue['source'] === 'github',
                'is_linear' => $issue['source'] === 'linear'
            ];

            // Process template
            $prompt = process_template($template, $templateData);

            // Save prompt to temp file
            $promptFile = tempnam(sys_get_temp_dir(), 'scout_prompt_');
            file_put_contents($promptFile, $prompt);

            // Update issue status
            $branchName = 'fix/' . $issue['source_id'] . '-' . slugify($issue['title']);
            db_query(
                "UPDATE issues
                 SET pr_status = 'in_progress',
                     pr_branch = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?",
                [$branchName, $issue_id]
            );

            // Save callback record
            db_query(
                "INSERT INTO callbacks (issue_id, callback_id, status, created_at)
                 VALUES (?, ?, 'pending', CURRENT_TIMESTAMP)",
                [$issue_id, $callback_id]
            );

            // Prepare callback URL
            $callback_url = 'http://localhost:8080/api/callback';

            // Get wrapper script path
            $wrapperScript = realpath(__DIR__ . '/../lib/scripts/claude-wrapper.sh');
            if (!file_exists($wrapperScript)) {
                throw new Exception('Claude wrapper script not found');
            }

            // Build command
            $command = sprintf(
                'bash %s %s %s %s %s %s',
                escapeshellarg($wrapperScript),
                escapeshellarg($issue['local_path']),
                escapeshellarg($promptFile),
                escapeshellarg($callback_url),
                escapeshellarg($callback_id),
                escapeshellarg($cliModel)
            );

            // Detect OS and launch in new terminal
            $os = PHP_OS_FAMILY;

            if ($os === 'Darwin') {
                // macOS: Use open command to launch Terminal
                // This is more reliable than AppleScript from PHP
                $windowTitle = sprintf('Scout: %s', $issue['source_id']);

                // Create a temporary shell script that Terminal will execute
                $shellScript = tempnam(sys_get_temp_dir(), 'scout_run_');
                $shellContent = <<<SHELL
#!/bin/bash
# Scout PR Creation for $windowTitle
echo -e "\033[0;34m========================================\033[0m"
echo -e "\033[0;34m        Scout: $issue[source_id]        \033[0m"
echo -e "\033[0;34m========================================\033[0m"
echo
exec $command
SHELL;

                file_put_contents($shellScript, $shellContent);
                chmod($shellScript, 0755);

                // Use open command to launch Terminal with the script
                $launchCommand = sprintf('open -a Terminal %s', escapeshellarg($shellScript));

                // Clean up script file after a delay (Terminal needs time to read it)
                register_shutdown_function(function() use ($shellScript) {
                    sleep(2);
                    @unlink($shellScript);
                });
            } elseif ($os === 'Linux') {
                // Linux: Try gnome-terminal first
                $launchCommand = sprintf(
                    'gnome-terminal -- bash -c %s',
                    escapeshellarg($command . '; echo "Press any key to close..."; read -n 1')
                );
            } else {
                // Windows or other: Not supported yet
                http_response_code(500);
                echo json_encode(['error' => 'Unsupported operating system: ' . $os]);
                break;
            }

            // Launch the command in background to avoid blocking
            // Use system() for better terminal interaction
            $fullCommand = $launchCommand . ' > /dev/null 2>&1 &';
            system($fullCommand, $returnCode);

            // Log the result for debugging
            error_log("Scout launch command: $fullCommand");
            error_log("Scout launch return code: $returnCode");

            if ($returnCode !== 0 && $os === 'Linux') {
                // Try xterm as fallback
                $launchCommand = sprintf(
                    'xterm -e bash -c %s',
                    escapeshellarg($command . '; echo "Press any key to close..."; read -n 1')
                );
                exec($launchCommand . ' 2>&1', $output, $returnCode);
            }

            // Clean up prompt file after a delay (it needs to be read by the wrapper)
            // Register a shutdown function to clean it up
            register_shutdown_function(function() use ($promptFile) {
                @unlink($promptFile);
            });

            // Return success
            echo json_encode([
                'status' => 'launched',
                'callback_id' => $callback_id,
                'branch' => $branchName,
                'model' => $cliModel
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