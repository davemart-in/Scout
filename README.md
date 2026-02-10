# Issue Agent

A local PHP application that connects to GitHub/Linear, pulls issues, uses AI to assess them, and launches Claude Code to create PRs.

## Status

Currently implementing the scaffolding (Prompt 1 of 11).

## Prerequisites

- PHP 8.0+
- SQLite3 extension
- Claude Code CLI (will be needed for PR creation)
- git

## Setup

1. Clone the repository
2. Run the startup script:
   ```bash
   bash lib/scripts/start.sh
   ```
3. Open http://localhost:8080 in your browser

## Project Structure

```
issue-agent/
├── public/           # Web-accessible files
├── api/              # API endpoints
├── lib/              # Core libraries
│   ├── scripts/      # Shell scripts
│   └── prompts/      # AI prompt templates
└── db/               # SQLite database (created at runtime)
```

## Stack

- Vanilla PHP (functions only, no frameworks or classes)
- SQLite for data storage
- HTML/CSS/vanilla JS for frontend
- No npm, no bundling, no build process
