# Scout

A local PHP application that connects to GitHub/Linear, pulls issues, uses AI to assess them, and launches Claude Code to create PRs.

## Implementation Progress

**Overall: 6/11 prompts completed (54%)**

### Completed ✅
- [x] **Prompt 1:** Project Scaffolding & Database
- [x] **Prompt 2:** Environment Variables Setup (.env configuration)
- [x] **Prompt 3:** Settings Modal & API (model selection, repo management)
- [x] **Prompt 4:** GitHub Integration (API functions, token validation, issue fetching)
- [x] **Prompt 5:** Linear Integration (GraphQL API, team/issue fetching)
- [x] **Prompt 6:** Main UI - Issues Table & Controls

### In Progress 🚧
- [ ] **Prompt 7:** AI Analysis System ← *Current Work*

### Not Started ⬜
- [ ] **Prompt 8:** Claude Code Launcher & Callback
- [ ] **Prompt 9:** Polling, Status Updates & PR Detection
- [ ] **Prompt 10:** Styling Polish (Vercel/shadcn aesthetic)
- [ ] **Prompt 11:** Error Handling, Edge Cases & Documentation

## Current Status

### What's Working Now
- ✅ Full settings interface with API key configuration
- ✅ GitHub repository connection and issue fetching
- ✅ Linear team connection and issue fetching
- ✅ Issues table with sorting, filtering, and status badges
- ✅ Model selection for AI analysis and PR creation
- ✅ Database persistence with SQLite

### What's Next
- 🚧 AI-powered issue assessment (categorizing issues as "PR capable" or "too complex")
- ⬜ Claude Code integration for automated PR creation
- ⬜ Real-time status updates and polling
- ⬜ Final UI polish and error handling

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
