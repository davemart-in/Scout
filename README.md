# Scout

A local PHP application that connects to GitHub/Linear, pulls issues, uses AI to assess them, and launches Claude Code to create PRs.

## Prerequisites

### Operating System
- **macOS** (required) - Terminal window integration currently only supports macOS
  - Linux support is partially implemented but not tested
  - Windows is not supported

### Software Requirements
- PHP 8.0+
- SQLite3 extension
- Claude Code CLI (required for PR creation)
- git
- Terminal.app (comes with macOS)

### Optional but Recommended
- GNU coreutils (for better timeout handling)
  ```bash
  brew install coreutils
  ```

## Setup

1. Clone the repository

2. Configure API keys in `.env` file:
   ```bash
   cp .env.example .env  # if example exists
   # Or create .env with:
   GITHUB_TOKEN=your_github_token_here
   LINEAR_TOKEN=your_linear_api_key_here
   OPENAI_KEY=your_openai_api_key_here
   ANTHROPIC_KEY=your_anthropic_api_key_here
   ```

   **GitHub Token Setup:**
   - Go to GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
   - Click "Generate new token (classic)"
   - Required scopes:
     - `repo` (Full control of private repositories) - for accessing issues and creating PRs
   - Optional scopes (if needed):
     - `workflow` - if you need to modify GitHub Actions workflows
     - `write:packages` - if working with GitHub Packages
     - `admin:org` → `read:org` - if you need to list organization repositories

3. Run the startup script:
   ```bash
   bash lib/scripts/start.sh
   ```

4. Open http://localhost:8080 in your browser

5. Configure your AI model preferences in Settings

## Project Structure

```
scout/
├── public/           # Web-accessible files
│   ├── index.php     # Main entry point
│   ├── app.js        # Application logic
│   ├── style.css     # Styles
│   └── templates.php # HTML templates
├── api/              # API endpoints
│   └── settings.php  # Settings & model preferences
├── lib/              # Core libraries
│   ├── db.php        # Database functions
│   ├── scripts/      # Shell scripts
│   └── prompts/      # AI prompt templates
├── db/               # SQLite database (created at runtime)
├── .env              # API keys and tokens (not tracked in git)
└── router.php        # PHP server router
```

## Stack

- Vanilla PHP (functions only, no frameworks or classes)
- SQLite for data storage
- HTML/CSS/vanilla JS for frontend
- No npm, no bundling, no build process
