<!-- Template: Main App -->
<script type="text/html" id="tmpl-mainApp">
    <!-- Top Bar -->
    <header class="top-bar">
        <div class="top-bar-left">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="app-icon">
                <circle cx="12" cy="12" r="10"></circle>
                <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"></polygon>
            </svg>
            <h1 class="app-name">Scout</h1>
        </div>
        <div class="top-bar-center">
            <select id="source-selector" class="form-select">
                {{sourceOptions}}
            </select>
            <select id="repo-selector" class="form-select">
                {{repoOptions}}
            </select>
        </div>
        <div class="top-bar-right">
            <button class="settings-btn" id="openSettings" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
            </button>
        </div>
    </header>

    <!-- Action Bar -->
    <div class="action-bar">
        <div class="action-bar-left">
            <button class="btn {{fetchButtonClass}}" id="fetch-issues">Fetch Issues</button>
            <button class="btn btn-secondary" id="refresh-issues" {{refreshDisabled}}>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><polyline points="1 20 1 14 7 14"></polyline><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path></svg>
                Refresh
            </button>
            <span class="issue-count">{{issueCount}}</span>
        </div>
        <div class="action-bar-right">
            <button class="btn {{analyzeButtonClass}}" id="analyze-all" {{analyzeDisabled}}>Analyze All</button>
            <span class="progress-indicator" style="display: {{progressDisplay}};">{{progressText}}</span>
        </div>
    </div>

    <!-- Main Content -->
    <main class="main-content">
        {{mainContent}}
    </main>

    <!-- Status Bar -->
    <footer class="status-bar">
        <span class="connection-status">{{connectionStatus}}</span>
        <span class="last-sync">{{lastSync}}</span>
    </footer>
</script>

<!-- Template: Settings Modal -->
<script type="text/html" id="tmpl-settingsModal">
    <div class="modal-overlay {{modalClass}}" id="settingsModalOverlay">
        <div class="modal {{modalClass}}" id="settingsModal">
            <div class="modal-header">
                <h2>Settings</h2>
                <button class="modal-close" id="closeModal">×</button>
            </div>

            <div class="modal-body">
                {{apiKeysAlert}}

                <!-- Model Selection Section -->
                <div class="settings-section">
                    <h3 class="section-title">AI Model Preferences</h3>
                    {{modelSelectionContent}}
                </div>

                <!-- Repositories Section -->
                <div class="settings-section">
                    <h3 class="section-title">Repositories / Teams</h3>
                    <div class="repo-actions">
                        {{repoActions}}
                    </div>
                    <div class="table-container">
                        <table class="repos-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Source</th>
                                    <th>Local Path</th>
                                    <th>Branch</th>
                                    <th>Auto PR</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reposTableBody">
                                {{repoRows}}
                            </tbody>
                        </table>
                        {{emptyState}}
                    </div>
                </div>
            </div>
        </div>
    </div>
</script>

<!-- Template: API Keys Alert -->
<script type="text/html" id="tmpl-apiKeysAlert">
    <div class="alert alert-warning">
        <h4>Missing API Configuration</h4>
        <p>{{missingKeysMessage}}</p>
        <p class="help-text">Add the missing keys to your .env file:</p>
        <code class="env-example">{{missingKeysExample}}</code>
    </div>
</script>

<!-- Template: Model Selection -->
<script type="text/html" id="tmpl-modelSelection">
    <div class="model-selection">
        <div class="form-group">
            <label for="assessment-model">Issue Assessment Model</label>
            <div class="model-select-row">
                <select id="assessment-model" class="form-select">
                    {{assessmentOptions}}
                </select>
                <button class="btn btn-secondary test-connection" data-model-type="assessment">Test connection</button>
            </div>
            <p class="help-text">Model used to analyze issues and determine if they're suitable for automated PR creation</p>
        </div>
        <div class="form-group">
            <label for="pr-creation-model">PR Creation Model</label>
            <div class="model-select-row">
                <select id="pr-creation-model" class="form-select">
                    {{prCreationOptions}}
                </select>
                <button class="btn btn-secondary test-connection" data-model-type="pr-creation">Test connection</button>
            </div>
            <p class="help-text">Model used by Claude Code to generate the actual pull request</p>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" id="save-model-preferences">Save Preferences</button>
        </div>
    </div>
</script>

<!-- Template: No Models Available -->
<script type="text/html" id="tmpl-noModels">
    <div class="empty-state">
        <p>No AI models available. Please configure at least one API key in your .env file.</p>
        <p class="help-text">Add one or more of the following to your .env file:</p>
        <code class="env-example">OPENAI_KEY=your_openai_api_key<br>ANTHROPIC_KEY=your_anthropic_api_key</code>
    </div>
</script>

<!-- Template: Repo Selection Modal -->
<script type="text/html" id="tmpl-repoSelectionModal">
    <div class="modal-overlay show" id="repoSelectionOverlay">
        <div class="modal show" id="repoSelectionModal">
            <div class="modal-header">
                <h2>Select Repositories to Add</h2>
                <button class="modal-close" id="closeRepoSelection">×</button>
            </div>
            <div class="modal-body">
                <div class="repo-selection-list">
                    {{repoCheckboxes}}
                </div>
                <div class="form-actions">
                    <button class="btn btn-primary" id="addSelectedRepos">Add Selected</button>
                    <button class="btn btn-secondary" id="cancelRepoSelection">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</script>

<!-- Template: Issues Table -->
<script type="text/html" id="tmpl-issuesTable">
    <div class="issues-table-container">
        <table class="issues-table">
            <thead>
                <tr>
                    <th>Issue</th>
                    <th>Labels</th>
                    <th>Priority</th>
                    <th>Created</th>
                    <th>Assessment</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                {{issueRows}}
            </tbody>
        </table>
    </div>
</script>

<!-- Template: Issue Row -->
<script type="text/html" id="tmpl-issueRow">
    <tr class="issue-row">
        <td class="issue-cell">
            <div class="issue-header">
                <a href="{{sourceUrl}}" target="_blank" class="issue-title">{{title}}</a>
                <span class="issue-id">{{sourceId}}</span>
            </div>
            <p class="issue-summary">{{summary}}</p>
        </td>
        <td class="labels-cell">
            {{labels}}
        </td>
        <td class="priority-cell">
            {{priority}}
        </td>
        <td class="created-cell">
            <span title="{{createdFull}}">{{createdRelative}}</span>
        </td>
        <td class="assessment-cell">
            {{assessmentBadge}}
        </td>
        <td class="action-cell">
            {{actionContent}}
        </td>
    </tr>
</script>

<!-- Template: Empty State -->
<script type="text/html" id="tmpl-emptyState">
    <div class="empty-state">
        <h3>{{title}}</h3>
        <p>{{message}}</p>
        {{action}}
    </div>
</script>

<!-- Template: Repository Row -->
<script type="text/html" id="tmpl-repoRow">
    <tr data-repo-id="{{id}}">
        <td>{{name}}</td>
        <td><span class="badge badge-{{source}}">{{source}}</span></td>
        <td>
            <input type="text" class="repo-local-path" value="{{localPath}}" placeholder="/path/to/repo">
        </td>
        <td>
            <input type="text" class="repo-branch" value="{{defaultBranch}}" placeholder="main">
        </td>
        <td>
            <label class="toggle">
                <input type="checkbox" class="repo-auto-pr" {{autoCreatePr}}>
                <span class="toggle-slider"></span>
            </label>
        </td>
        <td>
            <button class="btn btn-small btn-primary save-repo" data-repo-id="{{id}}" disabled>Save</button>
            <button class="btn btn-small btn-secondary delete-repo" data-repo-id="{{id}}">Delete</button>
        </td>
    </tr>
</script>