<?php

// Set JSON response header
header('Content-Type: application/json');

// Include required libraries
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/utils.php';

// Template processing and slugify functions moved to lib/utils.php

$cleanupRepoRoot = null;
$cleanupWorktreePath = null;
$cleanupPromptFiles = [];

/**
 * Create a worktree and recover once from stale Scout worktree metadata.
 */
function add_worktree_with_recovery($repoRootPathArg, $branchNameArg, $worktreePathArg, $originBranchArg) {
    $addOutput = [];
    $addCode = 0;
    exec("git -C $repoRootPathArg worktree add -b $branchNameArg $worktreePathArg $originBranchArg 2>&1", $addOutput, $addCode);

    if ($addCode === 0) {
        return;
    }

    $combinedOutput = implode("\n", $addOutput);
    $alreadyUsedPattern = "/fatal:\\s+'[^']+'\\s+is already used by worktree at\\s+'([^']+)'/";
    if (!preg_match($alreadyUsedPattern, $combinedOutput, $matches)) {
        throw new Exception('Failed to create worktree: ' . $combinedOutput);
    }

    $staleWorktreePath = $matches[1];
    $scoutWorktreeRoot = rtrim(sys_get_temp_dir(), '/') . '/scout-worktrees/';

    // Only force-remove stale paths that belong to Scout's temp worktree root.
    if (strpos($staleWorktreePath, $scoutWorktreeRoot) === 0 && is_dir($staleWorktreePath)) {
        $staleWorktreeArg = escapeshellarg($staleWorktreePath);
        exec("git -C $repoRootPathArg worktree remove --force $staleWorktreeArg 2>&1");
    }

    // Prune stale admin entries (including missing directories) before retrying once.
    exec("git -C $repoRootPathArg worktree prune 2>&1");

    $retryOutput = [];
    $retryCode = 0;
    exec("git -C $repoRootPathArg worktree add -b $branchNameArg $worktreePathArg $originBranchArg 2>&1", $retryOutput, $retryCode);
    if ($retryCode !== 0) {
        throw new Exception('Failed to create worktree: ' . implode("\n", $retryOutput));
    }
}

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
                "SELECT i.*, r.local_path, r.source, r.auto_create_pr, r.default_branch, r.default_mode
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

            // Get code creation model from settings
            $creationModel = get_setting('pr_creation_model');
            if (!$creationModel) {
                $creationModel = 'claude-opus-4-6';
            }

            // Get code review model from settings
            $reviewModel = get_setting('code_review_model');
            if (!$reviewModel) {
                if (!empty(get_env_value('OPENAI_KEY'))) {
                    $reviewModel = 'gpt-5.2';
                } elseif (!empty(get_env_value('ANTHROPIC_KEY'))) {
                    $reviewModel = 'claude-sonnet-4-5';
                } else {
                    $reviewModel = 'gpt-5.2';
                }
            }

            // Claude CLI model for code creation/rework/PR tasks
            $creationCliModel = get_claude_model_mapping($creationModel);

            // Generate unique callback ID
            $callback_id = uniqid('cb_');
            $short_callback = substr($callback_id, -6);

            $defaultBranch = $issue['default_branch'] ?: 'main';
            $defaultModeRaw = strtolower(trim((string)($issue['default_mode'] ?? 'plan')));
            $defaultMode = in_array($defaultModeRaw, ['accept', 'ask', 'plan'], true) ? $defaultModeRaw : 'plan';
            $branchName = 'fix/' . $issue['source_id'] . '-' . slugify($issue['title']) . '-' . $short_callback;

            $worktreeRoot = sys_get_temp_dir() . '/scout-worktrees/' . intval($issue['repo_id']);
            if (!is_dir($worktreeRoot) && !mkdir($worktreeRoot, 0755, true) && !is_dir($worktreeRoot)) {
                throw new Exception('Failed to create worktree directory');
            }
            $worktreePath = $worktreeRoot . '/' . $callback_id;

            $repoRootPathArg = escapeshellarg($issue['local_path']);
            $defaultBranchArg = escapeshellarg($defaultBranch);
            $branchNameArg = escapeshellarg($branchName);
            $worktreePathArg = escapeshellarg($worktreePath);
            $originBranchArg = escapeshellarg('origin/' . $defaultBranch);

            // Always branch from latest origin/default branch to avoid cross-PR contamination.
            $fetchOutput = [];
            $fetchCode = 0;
            exec("git -C $repoRootPathArg fetch origin $defaultBranchArg 2>&1", $fetchOutput, $fetchCode);
            if ($fetchCode !== 0) {
                throw new Exception('Failed to fetch origin/' . $defaultBranch . ': ' . implode("\n", $fetchOutput));
            }

            add_worktree_with_recovery($repoRootPathArg, $branchNameArg, $worktreePathArg, $originBranchArg);
            $cleanupRepoRoot = $issue['local_path'];
            $cleanupWorktreePath = $worktreePath;

            // Read run templates
            $implementTemplate = file_get_contents(__DIR__ . '/../lib/prompts/pr-creation.txt');
            $reviewTemplate = file_get_contents(__DIR__ . '/../lib/prompts/code-review.txt');
            $reworkTemplate = file_get_contents(__DIR__ . '/../lib/prompts/rework-from-review.txt');
            $prTemplate = file_get_contents(__DIR__ . '/../lib/prompts/create-pr.txt');
            if (!$implementTemplate || !$reviewTemplate || !$reworkTemplate || !$prTemplate) {
                throw new Exception('Could not read one or more launch prompt templates');
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
                'default_branch' => $defaultBranch,
                'branch_name' => $branchName,
                'callback_id' => $callback_id,
                'context' => $context,
                'has_context' => !empty($context),
                'auto_pr' => (bool)$issue['auto_create_pr'],
                'is_github' => $issue['source'] === 'github',
                'is_linear' => $issue['source'] === 'linear'
            ];

            // Process templates
            $implementPrompt = process_template($implementTemplate, $templateData);
            $reviewPrompt = process_template($reviewTemplate, $templateData);
            $reworkPromptTemplate = process_template($reworkTemplate, $templateData);
            $prPrompt = process_template($prTemplate, $templateData);

            // Save prompts to temp files
            $implementPromptFile = tempnam(sys_get_temp_dir(), 'scout_impl_');
            $reviewPromptFile = tempnam(sys_get_temp_dir(), 'scout_review_');
            $reworkPromptFile = tempnam(sys_get_temp_dir(), 'scout_rework_');
            $prPromptFile = tempnam(sys_get_temp_dir(), 'scout_pr_');
            if (!$implementPromptFile || !$reviewPromptFile || !$reworkPromptFile || !$prPromptFile) {
                throw new Exception('Failed to create temporary prompt files');
            }

            file_put_contents($implementPromptFile, $implementPrompt);
            file_put_contents($reviewPromptFile, $reviewPrompt);
            file_put_contents($reworkPromptFile, $reworkPromptTemplate);
            file_put_contents($prPromptFile, $prPrompt);
            $cleanupPromptFiles = [$implementPromptFile, $reviewPromptFile, $reworkPromptFile, $prPromptFile];

            // Update issue status
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
                "INSERT INTO callbacks (issue_id, callback_id, status, worktree_path, repo_root_path, branch_name, created_at)
                 VALUES (?, ?, 'pending', ?, ?, ?, CURRENT_TIMESTAMP)",
                [$issue_id, $callback_id, $worktreePath, $issue['local_path'], $branchName]
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
                'bash %s %s %s %s %s %s %s %s %s %s %s %s %s',
                escapeshellarg($wrapperScript),
                escapeshellarg($issue['local_path']),
                escapeshellarg($worktreePath),
                escapeshellarg($implementPromptFile),
                escapeshellarg($reviewPromptFile),
                escapeshellarg($reworkPromptFile),
                escapeshellarg($prPromptFile),
                escapeshellarg($callback_url),
                escapeshellarg($callback_id),
                escapeshellarg($creationCliModel),
                escapeshellarg($defaultMode),
                escapeshellarg($reviewModel),
                escapeshellarg((bool)$issue['auto_create_pr'] ? '1' : '0')
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
                throw new Exception('Unsupported operating system: ' . $os);
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

            if ($returnCode !== 0) {
                throw new Exception('Failed to launch terminal process');
            }

            // Return success
            echo json_encode([
                'status' => 'launched',
                'callback_id' => $callback_id,
                'branch' => $branchName,
                'model' => $creationCliModel
            ]);
            $cleanupRepoRoot = null;
            $cleanupWorktreePath = null;
            $cleanupPromptFiles = [];
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'error' => 'Method not allowed'
            ]);
            break;
    }

} catch (Exception $e) {
    if (!empty($cleanupRepoRoot) && !empty($cleanupWorktreePath) && is_dir($cleanupWorktreePath)) {
        $repoRootArg = escapeshellarg($cleanupRepoRoot);
        $worktreeArg = escapeshellarg($cleanupWorktreePath);
        shell_exec("git -C $repoRootArg worktree remove --force $worktreeArg 2>/dev/null");
    }
    if (!empty($cleanupPromptFiles) && is_array($cleanupPromptFiles)) {
        foreach ($cleanupPromptFiles as $cleanupPromptFile) {
            if (!empty($cleanupPromptFile) && file_exists($cleanupPromptFile)) {
                @unlink($cleanupPromptFile);
            }
        }
    }
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
