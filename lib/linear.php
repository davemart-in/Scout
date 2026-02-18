<?php

/**
 * Linear API Integration Library
 */

/**
 * Make a request to the Linear GraphQL API
 */
function linear_request($query, $token, $variables = []) {
    $url = 'https://api.linear.app/graphql';

    $headers = [
        'Authorization: ' . $token,  // Linear uses plain token format (not Bearer)
        'Content-Type: application/json'
    ];

    // Linear expects variables to be an object (not array) or omitted
    $request_body = ['query' => $query];
    if (!empty($variables)) {
        $request_body['variables'] = $variables;
    }

    $body = json_encode($request_body);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() is deprecated in PHP 8.5+ and has no effect since PHP 8.0

    // Handle HTTP errors
    if ($http_code >= 400) {
        // Try to get error details from response
        $error_details = json_decode($response, true);
        $error_msg = isset($error_details['errors'][0]['message'])
            ? $error_details['errors'][0]['message']
            : "HTTP $http_code";
        throw new Exception("Linear API error: $error_msg");
    }

    $data = json_decode($response, true);

    // Check for GraphQL errors
    if (isset($data['errors']) && !empty($data['errors'])) {
        $error_messages = array_map(function($err) {
            return $err['message'] ?? 'Unknown error';
        }, $data['errors']);

        // Log errors but don't fail if we have partial data
        error_log('Linear API errors: ' . implode(', ', $error_messages));

        // If we have no data at all, throw exception
        if (!isset($data['data'])) {
            throw new Exception('Linear API error: ' . implode(', ', $error_messages));
        }
    }

    return $data['data'] ?? [];
}

/**
 * List Linear teams
 */
function linear_list_teams($token) {
    $query = 'query {
        teams {
            nodes {
                id
                name
                key
            }
        }
    }';

    $data = linear_request($query, $token);

    $teams = [];
    if (isset($data['teams']['nodes'])) {
        foreach ($data['teams']['nodes'] as $team) {
            $teams[] = [
                'source_id' => $team['id'],
                'name' => $team['name'] . ' (' . $team['key'] . ')'
            ];
        }
    }

    return $teams;
}

/**
 * Fetch issues for a Linear team
 */
function linear_fetch_issues($token, $team_id, $limit = 100) {
    $query = 'query($teamId: String!, $first: Int!, $after: String) {
        team(id: $teamId) {
            issues(
                filter: { state: { type: { nin: ["completed", "canceled"] } } },
                first: $first,
                after: $after
            ) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                nodes {
                    id
                    identifier
                    url
                    title
                    description
                    priority
                    priorityLabel
                    labels {
                        nodes {
                            name
                        }
                    }
                    state {
                        name
                        type
                    }
                    createdAt
                }
            }
        }
    }';

    $all_issues = [];
    $after = null;
    $max_issues = 500; // Safety limit
    $per_page = min($limit, 100); // Linear's max per page is typically 100

    do {
        $variables = [
            'teamId' => $team_id,
            'first' => $per_page,
            'after' => $after
        ];

        $data = linear_request($query, $token, $variables);

        if (!isset($data['team']['issues'])) {
            break;
        }

        $issues = $data['team']['issues'];

        // Process issues
        foreach ($issues['nodes'] as $issue) {
            // Extract labels
            $labels = [];
            if (isset($issue['labels']['nodes'])) {
                foreach ($issue['labels']['nodes'] as $label) {
                    $labels[] = $label['name'];
                }
            }

            // Determine status from state type
            $status = 'open';
            if (isset($issue['state']['type'])) {
                $state_type = $issue['state']['type'];
                // Map Linear state types to our simple status
                if (in_array($state_type, ['completed', 'canceled'])) {
                    $status = 'closed';
                }
            }

            $all_issues[] = [
                'source_id' => $issue['identifier'], // Use human-readable identifier
                'source_url' => $issue['url'],
                'title' => $issue['title'],
                'description' => $issue['description'] ?? '',
                'labels' => $labels,
                'priority' => $issue['priorityLabel'], // Use the label (e.g., "High")
                'status' => $status,
                'created_at' => $issue['createdAt']
            ];

            if (count($all_issues) >= $max_issues) {
                break 2; // Break out of both loops
            }
        }

        // Check for next page
        $has_next = $issues['pageInfo']['hasNextPage'] ?? false;
        $after = $issues['pageInfo']['endCursor'] ?? null;

    } while ($has_next && count($all_issues) < $max_issues);

    return $all_issues;
}

/**
 * Fetch a single page of issues for a Linear team.
 */
function linear_fetch_issues_page($token, $team_id, $limit = 50, $after = null) {
    $query = 'query($teamId: String!, $first: Int!, $after: String) {
        team(id: $teamId) {
            issues(
                filter: { state: { type: { nin: ["completed", "canceled"] } } },
                first: $first,
                after: $after
            ) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                nodes {
                    id
                    identifier
                    url
                    title
                    description
                    priority
                    priorityLabel
                    labels {
                        nodes {
                            name
                        }
                    }
                    state {
                        name
                        type
                    }
                    createdAt
                }
            }
        }
    }';

    $variables = [
        'teamId' => $team_id,
        'first' => min(max(intval($limit), 1), 100),
        'after' => $after
    ];

    $data = linear_request($query, $token, $variables);
    $issuesData = $data['team']['issues'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

    $issues = [];
    foreach ($issuesData['nodes'] as $issue) {
        $labels = [];
        if (isset($issue['labels']['nodes'])) {
            foreach ($issue['labels']['nodes'] as $label) {
                $labels[] = $label['name'];
            }
        }

        $status = 'open';
        if (isset($issue['state']['type']) && in_array($issue['state']['type'], ['completed', 'canceled'])) {
            $status = 'closed';
        }

        $issues[] = [
            'source_id' => $issue['identifier'],
            'source_url' => $issue['url'],
            'title' => $issue['title'],
            'description' => $issue['description'] ?? '',
            'labels' => $labels,
            'priority' => $issue['priorityLabel'],
            'status' => $status,
            'created_at' => $issue['createdAt']
        ];
    }

    return [
        'issues' => $issues,
        'has_next' => (bool)($issuesData['pageInfo']['hasNextPage'] ?? false),
        'end_cursor' => $issuesData['pageInfo']['endCursor'] ?? null
    ];
}

/**
 * Validate a Linear token
 */
function linear_validate_token($token) {
    try {
        $query = 'query { viewer { id } }';
        $data = linear_request($query, $token);
        return isset($data['viewer']['id']);
    } catch (Exception $e) {
        return false;
    }
}
