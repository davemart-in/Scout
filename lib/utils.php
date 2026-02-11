<?php

/**
 * Utility functions for Scout
 */

/**
 * Get the default assessment model from settings with fallback
 */
function get_assessment_model($override = null) {
    if ($override) {
        return $override;
    }

    $model = get_setting('assessment_model');
    return $model ?: 'gpt-5.2'; // Default fallback
}

/**
 * Process mustache-style template with placeholders and conditionals
 */
function process_template($template, $data) {
    // Simple placeholder replacement
    foreach ($data as $key => $value) {
        if (!is_array($value) && !is_bool($value)) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }
    }

    // Handle conditional blocks
    // {{#condition}}content{{/condition}} - show if true
    // {{^condition}}content{{/condition}} - show if false
    foreach ($data as $key => $value) {
        if (is_bool($value) || in_array($key, ['is_github', 'is_linear', 'auto_pr', 'has_context'])) {
            // Positive condition
            $pattern = '/\{\{#' . preg_quote($key, '/') . '\}\}(.*?)\{\{\/' . preg_quote($key, '/') . '\}\}/s';
            if ($value) {
                $template = preg_replace($pattern, '$1', $template);
            } else {
                $template = preg_replace($pattern, '', $template);
            }

            // Negative condition
            $pattern = '/\{\{\^' . preg_quote($key, '/') . '\}\}(.*?)\{\{\/' . preg_quote($key, '/') . '\}\}/s';
            if (!$value) {
                $template = preg_replace($pattern, '$1', $template);
            } else {
                $template = preg_replace($pattern, '', $template);
            }
        }
    }

    return $template;
}

/**
 * Slugify a string for use in branch names
 */
function slugify($text) {
    // Convert to lowercase
    $text = strtolower($text);
    // Replace non-alphanumeric characters with hyphens
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    // Remove leading/trailing hyphens
    $text = trim($text, '-');
    // Limit length
    if (strlen($text) > 50) {
        $text = substr($text, 0, 50);
        $text = rtrim($text, '-');
    }
    return $text;
}

/**
 * Update or insert an issue in the database
 * Returns true if new issue, false if updated
 */
function upsert_issue($repo_id, $source, $issue_data) {
    // Check if issue exists
    $existing = db_get_one(
        "SELECT id, assessment, pr_status FROM issues
        WHERE source = ? AND source_id = ? AND repo_id = ?",
        [$source, $issue_data['source_id'], $repo_id]
    );

    // Prepare labels as JSON
    $labels_json = json_encode($issue_data['labels']);

    if ($existing) {
        // Update existing issue, preserving assessment and pr_status
        db_query(
            "UPDATE issues SET
                title = ?,
                description = ?,
                labels = ?,
                source_url = ?,
                status = ?,
                priority = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?",
            [
                $issue_data['title'],
                $issue_data['description'],
                $labels_json,
                $issue_data['source_url'],
                $issue_data['status'],
                $issue_data['priority'] ?? 'medium',
                $existing['id']
            ]
        );
        return false; // Updated
    } else {
        // Insert new issue
        db_query(
            "INSERT INTO issues (
                repo_id, source, source_id, source_url,
                title, description, labels, priority,
                status, assessment, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)",
            [
                $repo_id,
                $source,
                $issue_data['source_id'],
                $issue_data['source_url'],
                $issue_data['title'],
                $issue_data['description'],
                $labels_json,
                $issue_data['priority'] ?? 'medium',
                $issue_data['status'],
                $issue_data['created_at']
            ]
        );
        return true; // New
    }
}

/**
 * Get Claude model mapping
 */
function get_claude_model_mapping($model) {
    // Map friendly model names to CLI model names
    $modelMap = [
        'claude-opus-4-6' => 'claude-opus-4-6',
        'claude-sonnet-4-5' => 'claude-sonnet-4-5-20250929',
        'claude-3-5-sonnet-20241022' => 'claude-3-5-sonnet-20241022'
    ];
    return $modelMap[$model] ?? $model;  // Pass through if not mapped
}

/**
 * Get OpenAI API model mapping
 */
function get_openai_model_mapping($model) {
    $modelMap = [
        'GPT-5.2' => 'gpt-5.2',
        'GPT-4o-mini' => 'gpt-4o-mini',
        'gpt-5.2' => 'gpt-5.2',
        'gpt-4o-mini' => 'gpt-4o-mini'
    ];
    return $modelMap[$model] ?? $model;
}

/**
 * Get Anthropic API model mapping
 */
function get_anthropic_model_mapping($model) {
    // Updated model mappings for Anthropic API
    $modelMap = [
        'Claude Sonnet 4.5' => 'claude-3-5-sonnet-20241022',
        'Claude Opus 4.6' => 'claude-3-5-opus-20241022',
        'claude-sonnet-4-5' => 'claude-3-5-sonnet-20241022',
        'claude-opus-4-6' => 'claude-3-5-opus-20241022'
    ];
    return $modelMap[$model] ?? $model;
}

/**
 * Format issue labels as string
 */
function format_labels($labels) {
    if (empty($labels)) {
        return '';
    }

    if (is_string($labels)) {
        $labelsArray = json_decode($labels, true);
        if (is_array($labelsArray)) {
            return implode(', ', $labelsArray);
        }
        return $labels;
    }

    if (is_array($labels)) {
        return implode(', ', $labels);
    }

    return '';
}

/**
 * Make an API call with retry on JSON parsing failure
 */
function call_ai_with_retry($model, $prompt, $retryPrompt = null) {
    // Determine which API to use
    $isOpenAI = strpos($model, 'gpt') === 0;
    $apiKey = $isOpenAI
        ? get_env_value('OPENAI_KEY')
        : get_env_value('ANTHROPIC_KEY');

    if (!$apiKey) {
        $provider = $isOpenAI ? 'OpenAI' : 'Anthropic';
        throw new Exception("$provider API key not configured");
    }

    // Make first attempt
    $response = $isOpenAI
        ? openai_chat($prompt, $model, $apiKey)
        : anthropic_chat($prompt, $model, $apiKey);

    // Try to parse JSON
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE && $retryPrompt) {
        // Retry with reminder prompt
        $response = $isOpenAI
            ? openai_chat($retryPrompt, $model, $apiKey)
            : anthropic_chat($retryPrompt, $model, $apiKey);

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse AI response as JSON: ' . $response);
        }
    }

    return $result;
}

/**
 * Get available AI models based on configured API keys
 */
function get_available_models() {
    $available_models = [];

    if (!empty(get_env_value('OPENAI_KEY'))) {
        $available_models[] = ['value' => 'gpt-5.2', 'label' => 'GPT-5.2', 'provider' => 'openai'];
        $available_models[] = ['value' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'provider' => 'openai'];
    }

    if (!empty(get_env_value('ANTHROPIC_KEY'))) {
        $available_models[] = ['value' => 'claude-sonnet-4-5', 'label' => 'Claude Sonnet 4.5', 'provider' => 'anthropic'];
        $available_models[] = ['value' => 'claude-opus-4-6', 'label' => 'Claude Opus 4.6', 'provider' => 'anthropic'];
    }

    return $available_models;
}

/**
 * Check for PRs matching branches and update issues
 * Returns count of updated issues
 */
function check_and_update_prs($github_token, $github_repo, $issues_with_branches) {
    if (empty($github_repo) || empty($issues_with_branches)) {
        return 0;
    }

    require_once __DIR__ . '/github.php';

    $updated_count = 0;

    try {
        // Fetch all open PRs for the repository
        $prs = github_get_pulls($github_token, $github_repo);

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

    return $updated_count;
}