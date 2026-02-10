<?php

/**
 * AI API integration functions (OpenAI and Anthropic)
 */

require_once __DIR__ . '/db.php';

/**
 * Analyze an issue using AI
 * @param array $issue Issue data
 * @param string $model AI model name
 * @return array Analysis result
 */
function ai_analyze_issue($issue, $model) {
    // Read the assessment prompt template
    $template = file_get_contents(__DIR__ . '/prompts/assessment.txt');
    if (!$template) {
        throw new Exception('Could not read assessment prompt template');
    }

    // Replace placeholders with issue data
    $prompt = str_replace('{{title}}', $issue['title'] ?? '', $template);
    $prompt = str_replace('{{description}}', $issue['description'] ?? '', $prompt);

    // Format labels as comma-separated string
    $labels = '';
    if (!empty($issue['labels'])) {
        if (is_string($issue['labels'])) {
            $labelsArray = json_decode($issue['labels'], true);
            if (is_array($labelsArray)) {
                $labels = implode(', ', $labelsArray);
            } else {
                $labels = $issue['labels'];
            }
        } else if (is_array($issue['labels'])) {
            $labels = implode(', ', $issue['labels']);
        }
    }
    $prompt = str_replace('{{labels}}', $labels, $prompt);
    $prompt = str_replace('{{priority}}', $issue['priority'] ?? 'None', $prompt);

    // Route to the appropriate API based on model name
    $response = '';
    if (strpos($model, 'gpt') === 0) {
        // OpenAI model
        $apiKey = get_env_value('OPENAI_KEY');
        if (!$apiKey) {
            throw new Exception('OpenAI API key not configured');
        }
        $response = openai_chat($prompt, $model, $apiKey);
    } else if (strpos($model, 'claude') === 0) {
        // Anthropic model
        $apiKey = get_env_value('ANTHROPIC_KEY');
        if (!$apiKey) {
            throw new Exception('Anthropic API key not configured');
        }
        $response = anthropic_chat($prompt, $model, $apiKey);
    } else {
        throw new Exception('Unknown model type: ' . $model);
    }

    // Parse JSON response
    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If JSON parsing fails, retry with a reminder
        $retryPrompt = $prompt . "\n\nRemember: respond with ONLY valid JSON, no markdown formatting.";

        if (strpos($model, 'gpt') === 0) {
            $response = openai_chat($retryPrompt, $model, get_env_value('OPENAI_KEY'));
        } else {
            $response = anthropic_chat($retryPrompt, $model, get_env_value('ANTHROPIC_KEY'));
        }

        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse AI response as JSON: ' . $response);
        }
    }

    // Validate required fields
    if (!isset($result['assessment']) || !isset($result['summary'])) {
        throw new Exception('AI response missing required fields');
    }

    // Validate assessment value
    if (!in_array($result['assessment'], ['agentic_pr_capable', 'too_complex'])) {
        throw new Exception('Invalid assessment value: ' . $result['assessment']);
    }

    return [
        'assessment' => $result['assessment'],
        'summary' => $result['summary']
    ];
}

/**
 * Send chat request to OpenAI
 * @param string $prompt Prompt text
 * @param string $model Model name
 * @param string $api_key API key
 * @return string Response content
 */
function openai_chat($prompt, $model, $api_key) {
    // Map friendly model names to API model names
    $modelMap = [
        'GPT-5.2' => 'gpt-5.2',
        'GPT-4o-mini' => 'gpt-4o-mini',
        'gpt-5.2' => 'gpt-5.2',
        'gpt-4o-mini' => 'gpt-4o-mini'
    ];

    $apiModel = $modelMap[$model] ?? $model;

    // Prepare request
    $url = 'https://api.openai.com/v1/chat/completions';
    $headers = [
        'Authorization: Bearer ' . $api_key,
        'Content-Type: application/json'
    ];

    $data = [
        'model' => $apiModel,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3,
        'response_format' => ['type' => 'json_object']
    ];

    // Make API call
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new Exception('OpenAI API request failed');
    }

    $responseData = json_decode($response, true);

    if ($httpCode !== 200) {
        $error = $responseData['error']['message'] ?? 'Unknown error';
        if ($httpCode === 429) {
            throw new Exception('OpenAI API rate limit exceeded: ' . $error);
        } else if ($httpCode === 401) {
            throw new Exception('OpenAI API authentication failed: ' . $error);
        } else {
            throw new Exception('OpenAI API error (' . $httpCode . '): ' . $error);
        }
    }

    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception('Unexpected OpenAI API response format');
    }

    return $responseData['choices'][0]['message']['content'];
}

/**
 * Send chat request to Anthropic
 * @param string $prompt Prompt text
 * @param string $model Model name
 * @param string $api_key API key
 * @return string Response content
 */
function anthropic_chat($prompt, $model, $api_key) {
    // Map friendly model names to API model names
    $modelMap = [
        'Claude Sonnet 4.5' => 'claude-3-5-sonnet-20241022',
        'Claude Opus 4.5' => 'claude-3-5-opus-20241022',
        'Claude Opus 4.6' => 'claude-3-5-opus-20241022',
        'claude-sonnet-4-5' => 'claude-3-5-sonnet-20241022',
        'claude-opus-4-5' => 'claude-3-5-opus-20241022',
        'claude-opus-4-6' => 'claude-3-5-opus-20241022'
    ];

    $apiModel = $modelMap[$model] ?? $model;

    // Prepare request
    $url = 'https://api.anthropic.com/v1/messages';
    $headers = [
        'x-api-key: ' . $api_key,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
    ];

    $data = [
        'model' => $apiModel,
        'max_tokens' => 1024,
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ]
    ];

    // Make API call
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new Exception('Anthropic API request failed');
    }

    $responseData = json_decode($response, true);

    if ($httpCode !== 200) {
        $error = $responseData['error']['message'] ?? 'Unknown error';
        if ($httpCode === 429) {
            throw new Exception('Anthropic API rate limit exceeded: ' . $error);
        } else if ($httpCode === 401) {
            throw new Exception('Anthropic API authentication failed: ' . $error);
        } else {
            throw new Exception('Anthropic API error (' . $httpCode . '): ' . $error);
        }
    }

    if (!isset($responseData['content'][0]['text'])) {
        throw new Exception('Unexpected Anthropic API response format');
    }

    return $responseData['content'][0]['text'];
}