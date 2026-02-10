#!/bin/bash

# Claude Code Wrapper Script
# Launches Claude Code with a prompt and sends callback when complete

# Check arguments
if [ $# -lt 5 ]; then
    echo "Usage: $0 <repo_path> <prompt_file> <callback_url> <callback_id> <model>"
    exit 1
fi

REPO_PATH="$1"
PROMPT_FILE="$2"
CALLBACK_URL="$3"
CALLBACK_ID="$4"
MODEL="$5"

# Create temp directory for output
TEMP_DIR="/tmp/scout-claude-$$"
mkdir -p "$TEMP_DIR"
OUTPUT_FILE="$TEMP_DIR/output.log"

# Clean up on exit
trap "rm -rf $TEMP_DIR" EXIT

# Verify repo path exists
if [ ! -d "$REPO_PATH" ]; then
    echo "Error: Repository path does not exist: $REPO_PATH"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=repo_not_found" > /dev/null
    exit 1
fi

# Verify prompt file exists
if [ ! -f "$PROMPT_FILE" ]; then
    echo "Error: Prompt file does not exist: $PROMPT_FILE"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=prompt_not_found" > /dev/null
    exit 1
fi

# Check if claude command exists
if ! command -v claude &> /dev/null; then
    echo "Error: Claude Code CLI not found. Please install it first."
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=claude_not_installed" > /dev/null
    exit 1
fi

echo "Starting Claude Code in: $REPO_PATH"
echo "Using model: $MODEL"
echo "Callback ID: $CALLBACK_ID"

# Change to repository directory
cd "$REPO_PATH" || exit 1

# Run Claude with timeout (30 minutes)
# Capture output to file for PR URL extraction
timeout 1800 claude -p "$(cat "$PROMPT_FILE")" --model "$MODEL" 2>&1 | tee "$OUTPUT_FILE"

# Get exit code
EXIT_CODE=${PIPESTATUS[0]}

# Check for timeout
if [ $EXIT_CODE -eq 124 ]; then
    echo "Error: Claude Code timed out after 30 minutes"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=timeout" > /dev/null
    exit 1
fi

# Check output for completion/failure markers
if grep -q "ISSUE_AGENT_COMPLETE:${CALLBACK_ID}" "$OUTPUT_FILE"; then
    echo "Claude Code completed successfully"
    STATUS="complete"
elif grep -q "ISSUE_AGENT_FAILED:${CALLBACK_ID}" "$OUTPUT_FILE"; then
    echo "Claude Code reported failure"
    STATUS="failed"
elif [ $EXIT_CODE -eq 0 ]; then
    echo "Claude Code exited successfully (no marker found)"
    STATUS="complete"
else
    echo "Claude Code exited with error code: $EXIT_CODE"
    STATUS="failed"
fi

# Try to extract PR URL from output
PR_URL=""
if [ "$STATUS" = "complete" ]; then
    # Look for GitHub PR URL pattern
    PR_URL=$(grep -oE 'https://github\.com/[^/]+/[^/]+/pull/[0-9]+' "$OUTPUT_FILE" | head -1)
    if [ -n "$PR_URL" ]; then
        echo "Found PR URL: $PR_URL"
    fi
fi

# Send callback
if [ -n "$PR_URL" ]; then
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=${STATUS}&pr_url=${PR_URL}" > /dev/null
else
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=${STATUS}" > /dev/null
fi

echo "Callback sent with status: $STATUS"

# Exit with Claude's exit code
exit $EXIT_CODE