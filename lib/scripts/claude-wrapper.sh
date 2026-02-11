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

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Clean up function
cleanup() {
    echo -e "\n${YELLOW}Cleaning up...${NC}"
    rm -rf "$TEMP_DIR"
    rm -f "$PROMPT_FILE"
}

# Clean up on exit
trap cleanup EXIT

# Header
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}        Scout - Claude Code Launcher    ${NC}"
echo -e "${BLUE}========================================${NC}"
echo

# Verify repo path exists
if [ ! -d "$REPO_PATH" ]; then
    echo -e "${RED}❌ Error: Repository path does not exist:${NC}"
    echo "   $REPO_PATH"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=repo_not_found" > /dev/null
    echo -e "\n${YELLOW}Press any key to close this window...${NC}"
    read -n 1
    exit 1
fi

# Verify prompt file exists
if [ ! -f "$PROMPT_FILE" ]; then
    echo -e "${RED}❌ Error: Prompt file does not exist${NC}"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=prompt_not_found" > /dev/null
    echo -e "\n${YELLOW}Press any key to close this window...${NC}"
    read -n 1
    exit 1
fi

# Check if claude command exists
if ! command -v claude &> /dev/null; then
    echo -e "${RED}❌ Error: Claude Code CLI not found${NC}"
    echo "   Please install Claude Code CLI first"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=claude_not_installed" > /dev/null
    echo -e "\n${YELLOW}Press any key to close this window...${NC}"
    read -n 1
    exit 1
fi

echo -e "${GREEN}✅ Repository:${NC} $REPO_PATH"
echo -e "${GREEN}✅ Model:${NC} $MODEL"
echo -e "${GREEN}✅ Session ID:${NC} $CALLBACK_ID"
echo
echo -e "${CYAN}⏱️  Timeout: 15 minutes${NC}"
echo -e "${CYAN}📝 Logs saved to: $OUTPUT_FILE${NC}"
echo
echo -e "${BLUE}========================================${NC}"
echo -e "${YELLOW}Starting Claude Code...${NC}"
echo -e "${BLUE}========================================${NC}"
echo

# Change to repository directory
cd "$REPO_PATH" || exit 1

# Run Claude directly and interactively
echo -e "${CYAN}Starting Claude Code session...${NC}"
echo -e "${BLUE}----------------------------------------${NC}"
echo

# Show the prompt file for debugging
echo -e "${YELLOW}Prompt file: $PROMPT_FILE${NC}"
echo -e "${YELLOW}Model: $MODEL${NC}"
echo

# Create a timeout monitor
(
    sleep 900
    echo -e "\n${RED}⏰ Timeout reached (15 minutes). Terminating Claude...${NC}"
    pkill -P $$ claude 2>/dev/null
) &
TIMEOUT_PID=$!

# Run Claude directly - no script, no tee, just plain claude
# Output goes directly to terminal AND we redirect a copy to the log file
echo -e "${GREEN}Launching Claude Code CLI...${NC}"
echo -e "${CYAN}════════════════════════════════════════${NC}"
echo

# First, let's verify the prompt file exists and show first few lines
if [ -f "$PROMPT_FILE" ]; then
    echo -e "${BLUE}Prompt preview:${NC}"
    head -3 "$PROMPT_FILE"
    echo "..."
    echo
fi

# The solution: Run Claude interactively by passing the prompt as a command argument
# This allows Claude to start in interactive mode and process the initial prompt
echo -e "${YELLOW}Starting Claude in PLAN MODE...${NC}"
echo -e "${CYAN}Claude will first show you a plan, then execute git commands to:${NC}"
echo -e "${CYAN}  • Create a new branch${NC}"
echo -e "${CYAN}  • Make the necessary code changes${NC}"
echo -e "${CYAN}  • Commit and push the changes${NC}"
echo -e "${CYAN}  • Create a PR (if configured)${NC}"
echo
echo -e "${GREEN}You can approve, modify, or cancel the plan before execution.${NC}"
echo

# Read the prompt into a variable
PROMPT_TEXT=$(cat "$PROMPT_FILE")

# Run Claude interactively with the prompt as the initial message
# Use script to capture output while maintaining full terminal interactivity
if [[ "$OSTYPE" == "darwin"* ]]; then
    # macOS: Pass the prompt as an argument to Claude (not piped)
    script -q "$OUTPUT_FILE" claude --model "$MODEL" "$PROMPT_TEXT"
    EXIT_CODE=$?
else
    # Linux: Use script with -c flag
    script -q -c "claude --model '$MODEL' '$PROMPT_TEXT'" "$OUTPUT_FILE"
    EXIT_CODE=$?
fi

# Kill the timeout monitor if Claude finished before timeout
kill $TIMEOUT_PID 2>/dev/null 2>&1

echo
echo -e "${BLUE}========================================${NC}"

# Check for timeout
if [ $EXIT_CODE -eq 124 ]; then
    echo -e "${RED}❌ Claude Code timed out after 15 minutes${NC}"
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=failed&error=timeout" > /dev/null
    echo -e "\n${YELLOW}Press any key to close this window...${NC}"
    read -n 1
    exit 1
fi

# Check for API errors in the output
if grep -q "API Error:" "$OUTPUT_FILE" || grep -q '"type":"error"' "$OUTPUT_FILE"; then
    echo -e "${RED}❌ Claude Code encountered an API error${NC}"
    STATUS="failed"
# Check output for completion/failure markers
elif grep -q "ISSUE_AGENT_COMPLETE:${CALLBACK_ID}" "$OUTPUT_FILE"; then
    echo -e "${GREEN}✅ Claude Code completed successfully${NC}"
    STATUS="complete"
elif grep -q "ISSUE_AGENT_FAILED:${CALLBACK_ID}" "$OUTPUT_FILE"; then
    echo -e "${RED}❌ Claude Code reported failure${NC}"
    STATUS="failed"
elif [ $EXIT_CODE -eq 0 ]; then
    # Only mark as complete if there's actual output from Claude (not just an error)
    # Check if Claude actually did something by looking for common output patterns
    if grep -q "I'll\|I will\|Let me\|Here's\|I've\|Created\|Updated\|Fixed" "$OUTPUT_FILE"; then
        echo -e "${GREEN}✅ Claude Code completed successfully${NC}"
        STATUS="complete"
    else
        echo -e "${YELLOW}⚠️  Claude Code exited but may not have completed the task${NC}"
        STATUS="failed"
    fi
else
    echo -e "${RED}❌ Claude Code exited with error code: $EXIT_CODE${NC}"
    STATUS="failed"
fi

# Try to extract PR URL from output
PR_URL=""
if [ "$STATUS" = "complete" ]; then
    # Look for GitHub PR URL pattern
    PR_URL=$(grep -oE 'https://github\.com/[^/]+/[^/]+/pull/[0-9]+' "$OUTPUT_FILE" | head -1)
    if [ -n "$PR_URL" ]; then
        echo -e "${GREEN}🔗 Found PR URL:${NC} $PR_URL"
    fi

    # Also check for branch creation
    BRANCH=$(git branch --show-current 2>/dev/null)
    if [ -n "$BRANCH" ] && [[ "$BRANCH" == fix/* ]]; then
        echo -e "${GREEN}🌿 Created branch:${NC} $BRANCH"
    fi
fi

# Send callback
echo -e "\n${CYAN}📤 Sending callback to Scout...${NC}"
if [ -n "$PR_URL" ]; then
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=${STATUS}&pr_url=${PR_URL}" > /dev/null
else
    curl -s "${CALLBACK_URL}?id=${CALLBACK_ID}&status=${STATUS}" > /dev/null
fi

if [ "$STATUS" = "complete" ]; then
    echo -e "${GREEN}✅ Success! Scout has been notified.${NC}"
else
    echo -e "${YELLOW}⚠️  Scout has been notified of the failure.${NC}"
fi

echo -e "${BLUE}========================================${NC}"
echo -e "\n${YELLOW}Press any key to close this window...${NC}"
read -n 1

# Exit with Claude's exit code
exit $EXIT_CODE