# Scout

A local PHP application that connects to GitHub/Linear, pulls issues, uses AI to assess them, and launches Claude Code to create PRs.

## Status

- ✅ Project scaffolding complete
- ✅ Settings interface with model selection
- ✅ Template system implemented
- 🚧 Ready for GitHub/Linear integrations (Prompt 4)

## Prerequisites

- PHP 8.0+
- SQLite3 extension
- Claude Code CLI (will be needed for PR creation)
- git

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
