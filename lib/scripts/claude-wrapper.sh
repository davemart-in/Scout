#!/bin/bash

# Scout multi-stage runner:
# 1) Implement code
# 2) Review code (Claude or Codex based on review model)
# 3) Rework + re-review loop (max 2 attempts)
# 4) Create PR (optional)

if [ $# -lt 12 ]; then
    echo "Usage: $0 <repo_root_path> <worktree_path> <implement_prompt_file> <review_prompt_file> <rework_prompt_file> <pr_prompt_file> <callback_url> <callback_id> <creation_model> <mode> <review_model> <auto_pr>"
    exit 1
fi

REPO_ROOT_PATH="$1"
WORKTREE_PATH="$2"
IMPLEMENT_PROMPT_FILE="$3"
REVIEW_PROMPT_FILE="$4"
REWORK_PROMPT_FILE="$5"
PR_PROMPT_FILE="$6"
CALLBACK_URL="$7"
CALLBACK_ID="$8"
CREATION_MODEL="$9"
MODE_RAW="${10}"
REVIEW_MODEL="${11}"
AUTO_PR="${12}"

MODE="${MODE_RAW,,}"
REVIEW_MODEL_LOWER="${REVIEW_MODEL,,}"
MAX_REVIEW_LOOPS=2

case "$MODE" in
    accept)
        CREATION_PERMISSION_MODE="acceptEdits"
        ;;
    ask)
        CREATION_PERMISSION_MODE="default"
        ;;
    plan|*)
        MODE="plan"
        CREATION_PERMISSION_MODE="plan"
        ;;
esac

# Keep review in read-only planning mode for Claude review runs.
REVIEW_PERMISSION_MODE="plan"

if [[ "$REVIEW_MODEL_LOWER" == gpt* ]]; then
    REVIEW_ENGINE="codex"
else
    REVIEW_ENGINE="claude"
fi

TEMP_DIR="/tmp/scout-run-$$"
mkdir -p "$TEMP_DIR"
IMPLEMENT_LOG="$TEMP_DIR/implement.log"
REVIEW_LOG="$TEMP_DIR/review.log"
REWORK_LOG="$TEMP_DIR/rework.log"
PR_LOG="$TEMP_DIR/pr.log"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

CALLBACK_SENT=0

send_callback() {
    local status="$1"
    local pr_url="$2"
    local error="$3"

    if [ "$CALLBACK_SENT" -eq 1 ]; then
        return
    fi

    CALLBACK_SENT=1

    local url="${CALLBACK_URL}?id=${CALLBACK_ID}&status=${status}"
    if [ -n "$pr_url" ]; then
        url="${url}&pr_url=${pr_url}"
    fi
    if [ -n "$error" ]; then
        url="${url}&error=${error}"
    fi

    curl -s "$url" > /dev/null
}

cleanup() {
    echo -e "\n${YELLOW}Cleaning up...${NC}"
    cd /tmp || true

    if [ -n "$WORKTREE_PATH" ] && [ -n "$REPO_ROOT_PATH" ]; then
        git -C "$REPO_ROOT_PATH" worktree remove --force "$WORKTREE_PATH" >/dev/null 2>&1
        if [ -d "$WORKTREE_PATH" ]; then
            rm -rf "$WORKTREE_PATH"
        fi
        git -C "$REPO_ROOT_PATH" worktree prune >/dev/null 2>&1
    fi

    rm -rf "$TEMP_DIR"
    rm -f "$IMPLEMENT_PROMPT_FILE" "$REVIEW_PROMPT_FILE" "$REWORK_PROMPT_FILE" "$PR_PROMPT_FILE"
}

trap cleanup EXIT

run_claude_prompt() {
    local prompt_file="$1"
    local permission_mode="$2"
    local model="$3"
    local output_file="$4"

    local prompt_text
    prompt_text=$(cat "$prompt_file")

    if [[ "$OSTYPE" == "darwin"* ]]; then
        script -q "$output_file" claude --permission-mode "$permission_mode" --model "$model" "$prompt_text"
        return $?
    fi

    script -q -c "PROMPT_TEXT=\$(cat \"$prompt_file\"); claude --permission-mode '$permission_mode' --model '$model' \"\$PROMPT_TEXT\"" "$output_file"
    return $?
}

run_codex_review() {
    local prompt_file="$1"
    local model="$2"
    local output_file="$3"

    local prompt_text
    prompt_text=$(cat "$prompt_file")

    codex exec -C "$WORKTREE_PATH" --model "$model" "$prompt_text" > "$output_file" 2>&1
    return $?
}

extract_feedback() {
    local file="$1"
    awk '/REVIEW_FEEDBACK_START/{flag=1; next} /REVIEW_FEEDBACK_END/{flag=0} flag' "$file"
}

write_rework_prompt_with_feedback() {
    local feedback="$1"
    local out_file="$2"

    {
        cat "$REWORK_PROMPT_FILE"
        echo
        echo "REVIEW FEEDBACK TO ADDRESS:"
        if [ -n "$feedback" ]; then
            echo "$feedback"
        else
            echo "No structured feedback was provided. Resolve all review concerns and improve correctness/tests."
        fi
    } > "$out_file"
}

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}            Scout Runner                ${NC}"
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}Repo Root:${NC} $REPO_ROOT_PATH"
echo -e "${GREEN}Worktree:${NC} $WORKTREE_PATH"
echo -e "${GREEN}Creation Model:${NC} $CREATION_MODEL"
echo -e "${GREEN}Review Model:${NC} $REVIEW_MODEL"
echo -e "${GREEN}Review Engine:${NC} $REVIEW_ENGINE"
echo -e "${GREEN}Creation Mode:${NC} $MODE ($CREATION_PERMISSION_MODE)"
echo -e "${GREEN}Auto PR:${NC} $AUTO_PR"
echo -e "${GREEN}Session ID:${NC} $CALLBACK_ID"
echo

if [ ! -d "$WORKTREE_PATH" ]; then
    echo -e "${RED}Error: Worktree path does not exist: $WORKTREE_PATH${NC}"
    send_callback "failed" "" "repo_not_found"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
fi

for required_file in "$IMPLEMENT_PROMPT_FILE" "$REVIEW_PROMPT_FILE" "$REWORK_PROMPT_FILE" "$PR_PROMPT_FILE"; do
    if [ ! -f "$required_file" ]; then
        echo -e "${RED}Error: Missing prompt file: $required_file${NC}"
        send_callback "failed" "" "prompt_not_found"
        read -n 1 -p "Press any key to close..."
        echo
        exit 1
    fi
done

if ! command -v claude >/dev/null 2>&1; then
    echo -e "${RED}Error: Claude CLI not found${NC}"
    send_callback "failed" "" "claude_not_installed"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
fi

if [ "$REVIEW_ENGINE" = "codex" ] && ! command -v codex >/dev/null 2>&1; then
    echo -e "${RED}Error: Codex CLI not found but review model requires Codex review${NC}"
    send_callback "failed" "" "codex_not_installed"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
fi

cd "$WORKTREE_PATH" || exit 1

# Stage 1: implementation
echo -e "${CYAN}[1/3] Implementing changes...${NC}"
run_claude_prompt "$IMPLEMENT_PROMPT_FILE" "$CREATION_PERMISSION_MODE" "$CREATION_MODEL" "$IMPLEMENT_LOG"
IMPLEMENT_EXIT=$?
if [ $IMPLEMENT_EXIT -ne 0 ]; then
    echo -e "${RED}Implementation step failed with exit code $IMPLEMENT_EXIT${NC}"
    send_callback "failed" "" "implementation_failed"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
fi

if grep -q "ISSUE_IMPLEMENT_FAILED:${CALLBACK_ID}" "$IMPLEMENT_LOG"; then
    echo -e "${RED}Implementation reported failure${NC}"
    send_callback "failed" "" "implementation_failed"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
fi

if grep -q "ISSUE_IMPLEMENT_COMPLETE:${CALLBACK_ID}" "$IMPLEMENT_LOG"; then
    echo -e "${GREEN}Implementation completion marker found${NC}"
else
    echo -e "${YELLOW}Implementation completion marker not found; continuing based on successful command exit${NC}"
fi

# Stage 2: review loop
REVIEW_PASSED=0
for attempt in $(seq 1 "$MAX_REVIEW_LOOPS"); do
    echo -e "${CYAN}[2/3] Code review attempt ${attempt}/${MAX_REVIEW_LOOPS}...${NC}"

    if [ "$REVIEW_ENGINE" = "codex" ]; then
        run_codex_review "$REVIEW_PROMPT_FILE" "$REVIEW_MODEL" "$REVIEW_LOG"
        REVIEW_EXIT=$?
    else
        run_claude_prompt "$REVIEW_PROMPT_FILE" "$REVIEW_PERMISSION_MODE" "$REVIEW_MODEL" "$REVIEW_LOG"
        REVIEW_EXIT=$?
    fi

    if [ $REVIEW_EXIT -ne 0 ]; then
        echo -e "${RED}Review step failed with exit code $REVIEW_EXIT${NC}"
        send_callback "failed" "" "review_failed"
        read -n 1 -p "Press any key to close..."
        echo
        exit 1
    fi

    if grep -q "ISSUE_REVIEW_PASS:${CALLBACK_ID}" "$REVIEW_LOG"; then
        REVIEW_PASSED=1
        echo -e "${GREEN}Review passed${NC}"
        break
    fi

    if grep -q "ISSUE_REVIEW_FAIL:${CALLBACK_ID}" "$REVIEW_LOG"; then
        feedback=$(extract_feedback "$REVIEW_LOG")
        if [ "$attempt" -ge "$MAX_REVIEW_LOOPS" ]; then
            echo -e "${YELLOW}Review did not pass after ${MAX_REVIEW_LOOPS} attempts${NC}"
            send_callback "needs_review" "" "review_loop_limit_reached"
            read -n 1 -p "Press any key to close..."
            echo
            exit 0
        fi

        rework_prompt="$TEMP_DIR/rework-attempt-${attempt}.txt"
        write_rework_prompt_with_feedback "$feedback" "$rework_prompt"

        echo -e "${CYAN}[2/3] Reworking code from review feedback...${NC}"
        run_claude_prompt "$rework_prompt" "$CREATION_PERMISSION_MODE" "$CREATION_MODEL" "$REWORK_LOG"
        REWORK_EXIT=$?

        if [ $REWORK_EXIT -ne 0 ] || grep -q "ISSUE_REWORK_FAILED:${CALLBACK_ID}" "$REWORK_LOG"; then
            echo -e "${RED}Rework step failed${NC}"
            send_callback "failed" "" "rework_failed"
            read -n 1 -p "Press any key to close..."
            echo
            exit 1
        fi

        if grep -q "ISSUE_REWORK_COMPLETE:${CALLBACK_ID}" "$REWORK_LOG"; then
            echo -e "${GREEN}Rework completion marker found${NC}"
        else
            echo -e "${YELLOW}Rework completion marker not found; continuing based on successful command exit${NC}"
        fi

        continue
    fi

    echo -e "${RED}Review result markers not found${NC}"
    send_callback "failed" "" "review_marker_missing"
    read -n 1 -p "Press any key to close..."
    echo
    exit 1
done

if [ "$REVIEW_PASSED" -ne 1 ]; then
    echo -e "${YELLOW}Review not passed; marking needs review${NC}"
    send_callback "needs_review" "" "review_not_passed"
    read -n 1 -p "Press any key to close..."
    echo
    exit 0
fi

# Stage 3: PR creation (optional)
PR_URL=""
if [ "$AUTO_PR" = "1" ]; then
    echo -e "${CYAN}[3/3] Creating pull request...${NC}"
    run_claude_prompt "$PR_PROMPT_FILE" "default" "$CREATION_MODEL" "$PR_LOG"
    PR_EXIT=$?

    if [ $PR_EXIT -ne 0 ] || grep -q "ISSUE_PR_FAILED:${CALLBACK_ID}" "$PR_LOG"; then
        echo -e "${YELLOW}PR creation did not complete automatically${NC}"
        send_callback "needs_review" "" "pr_creation_failed"
        read -n 1 -p "Press any key to close..."
        echo
        exit 0
    fi

    PR_URL=$(grep -oE 'https://github\.com/[^/]+/[^/]+/pull/[0-9]+' "$PR_LOG" | head -1)

    if ! grep -q "ISSUE_PR_CREATED:${CALLBACK_ID}" "$PR_LOG"; then
        echo -e "${YELLOW}PR marker missing; treating as needs review${NC}"
        send_callback "needs_review" "$PR_URL" "pr_marker_missing"
        read -n 1 -p "Press any key to close..."
        echo
        exit 0
    fi

    if [ -z "$PR_URL" ]; then
        PR_URL=$(grep -oE 'https://github\.com/[^/]+/[^/]+/pull/[0-9]+' "$IMPLEMENT_LOG" | head -1)
    fi
fi

echo -e "${GREEN}Run completed successfully${NC}"
send_callback "complete" "$PR_URL" ""

echo -e "${YELLOW}Press any key to close this window...${NC}"
read -n 1

echo
exit 0
