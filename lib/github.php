<?php

/**
 * GitHub API Integration Library
 */

/**
 * Make a request to the GitHub API
 */
function github_request($endpoint, $token, $method = 'GET', $data = null) {
    $url = 'https://api.github.com' . $endpoint;

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: Scout-App'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HEADER, true); // Include headers in output to get rate limit info

    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is deprecated in PHP 8.5+ and has no effect since PHP 8.0

    // Split header and body
    $headers_string = substr($response, 0, $header_size);
    $body = substr($response, $header_size);

    // Parse headers
    $headers = [];
    foreach (explode("\r\n", $headers_string) as $header) {
        if (strpos($header, ':') !== false) {
            list($key, $value) = explode(':', $header, 2);
            $headers[trim($key)] = trim($value);
        }
    }

    // Check rate limiting
    if (isset($headers['X-RateLimit-Remaining'])) {
        $remaining = intval($headers['X-RateLimit-Remaining']);
        if ($remaining < 10) {
            error_log("WARNING: GitHub API rate limit low: $remaining requests remaining");
        }
    }

    // Handle errors
    if ($http_code >= 400) {
        $error_data = json_decode($body, true);
        $error_message = $error_data['message'] ?? 'Unknown error';
        throw new Exception("GitHub API error ($http_code): $error_message");
    }

    $result = json_decode($body, true);

    // Add Link header for pagination support
    if (isset($headers['Link'])) {
        $result['_pagination'] = parse_link_header($headers['Link']);
    }

    return $result;
}

/**
 * Parse GitHub's Link header for pagination
 */
function parse_link_header($link_header) {
    $links = [];
    $parts = explode(',', $link_header);

    foreach ($parts as $part) {
        if (preg_match('/<([^>]+)>;\s*rel="([^"]+)"/', trim($part), $matches)) {
            $url = $matches[1];
            $rel = $matches[2];

            // Extract page number from URL
            if (preg_match('/[?&]page=(\d+)/', $url, $page_matches)) {
                $links[$rel] = [
                    'url' => $url,
                    'page' => intval($page_matches[1])
                ];
            }
        }
    }

    return $links;
}

/**
 * List repositories for the authenticated user
 */
function github_list_repos($token) {
    $repos = [];
    $page = 1;
    $per_page = 100;

    do {
        $response = github_request(
            "/user/repos?sort=updated&per_page=$per_page&page=$page&type=all",
            $token
        );

        foreach ($response as $key => $repo) {
            if ($key === '_pagination') continue;
            if (is_array($repo) && isset($repo['full_name'])) {
                $repos[] = [
                    'source_id' => $repo['full_name'],
                    'name' => $repo['full_name']
                ];
            }
        }

        // Check for next page
        $has_next = isset($response['_pagination']['next']);
        $page++;

    } while ($has_next && count($repos) < 500); // Reasonable limit

    return $repos;
}

/**
 * Fetch issues from a GitHub repository
 */
function github_fetch_issues($token, $repo_full_name, $per_page = 100, $page = 1) {
    $all_issues = [];
    $total_fetched = 0;
    $max_issues = 500; // Safety limit

    do {
        // Use GitHub's search API to get only issues (not PRs)
        // This is more efficient than fetching everything and filtering
        $response = github_request(
            "/search/issues?q=repo:$repo_full_name+type:issue+state:open&per_page=$per_page&page=$page&sort=created&order=desc",
            $token
        );

        // Search API returns items in a different structure
        $items = isset($response['items']) ? $response['items'] : [];

        $issues_batch = [];
        foreach ($items as $issue) {
            // Extract labels
            $labels = [];
            if (isset($issue['labels']) && is_array($issue['labels'])) {
                foreach ($issue['labels'] as $label) {
                    $labels[] = $label['name'];
                }
            }

            $issues_batch[] = [
                'source_id' => strval($issue['number']),
                'source_url' => $issue['html_url'],
                'title' => $issue['title'],
                'description' => $issue['body'] ?? '',
                'labels' => $labels,
                'priority' => null, // GitHub doesn't have built-in priority
                'status' => 'open',
                'created_at' => $issue['created_at']
            ];

            $total_fetched++;
        }

        $all_issues = array_merge($all_issues, $issues_batch);

        // Check for next page
        $has_next = isset($response['_pagination']['next']);
        $page++;

    } while ($has_next && $total_fetched < $max_issues);

    // Log summary for debugging
    error_log("GitHub fetch for $repo_full_name: Found $total_fetched issues (max: $max_issues)");

    return $all_issues;
}

/**
 * Fetch a single page of issues from a GitHub repository.
 */
function github_fetch_issues_page($token, $repo_full_name, $per_page = 50, $page = 1) {
    $response = github_request(
        "/search/issues?q=repo:$repo_full_name+type:issue+state:open&per_page=$per_page&page=$page&sort=created&order=desc",
        $token
    );

    $items = isset($response['items']) ? $response['items'] : [];
    $issues = [];

    foreach ($items as $issue) {
        $labels = [];
        if (isset($issue['labels']) && is_array($issue['labels'])) {
            foreach ($issue['labels'] as $label) {
                $labels[] = $label['name'];
            }
        }

        $issues[] = [
            'source_id' => strval($issue['number']),
            'source_url' => $issue['html_url'],
            'title' => $issue['title'],
            'description' => $issue['body'] ?? '',
            'labels' => $labels,
            'priority' => null,
            'status' => 'open',
            'created_at' => $issue['created_at']
        ];
    }

    $has_next = isset($response['_pagination']['next']);

    return [
        'issues' => $issues,
        'has_next' => $has_next,
        'page' => $page
    ];
}

/**
 * Get pull requests for a repository
 */
function github_get_pulls($token, $repo_full_name) {
    // Use the existing github_request function
    return github_request(
        "/repos/$repo_full_name/pulls?state=open&per_page=100",
        $token
    );
}

/**
 * Validate a GitHub token
 */
function github_validate_token($token) {
    try {
        $response = github_request('/user', $token);
        return isset($response['login']); // If we get a user login, token is valid
    } catch (Exception $e) {
        return false;
    }
}
