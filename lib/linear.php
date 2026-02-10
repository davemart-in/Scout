<?php

/**
 * Linear API integration functions
 * This file will be populated in Prompt 5
 */

/**
 * Make a Linear GraphQL API request
 * @param string $query GraphQL query
 * @param string $token Linear token
 * @param array $variables Query variables
 * @return array Response data
 */
function linear_request($query, $token, $variables = []) {
    // Placeholder - will be implemented in Prompt 5
    return [];
}

/**
 * List Linear teams
 * @param string $token Linear token
 * @return array Team list
 */
function linear_list_teams($token) {
    // Placeholder - will be implemented in Prompt 5
    return [];
}

/**
 * Fetch issues for a team
 * @param string $token Linear token
 * @param string $team_id Team ID
 * @param int $limit Maximum number of issues
 * @return array Issue list
 */
function linear_fetch_issues($token, $team_id, $limit = 100) {
    // Placeholder - will be implemented in Prompt 5
    return [];
}

/**
 * Validate Linear token
 * @param string $token Linear token
 * @return bool Token is valid
 */
function linear_validate_token($token) {
    // Placeholder - will be implemented in Prompt 5
    return false;
}