<!-- Template: Main App -->
<script type="text/html" id="tmpl-mainApp">
    <header class="app-header">
        <div class="header-left">
            <h1>Scout</h1>
        </div>
        <div class="header-right">
            <button class="settings-btn" id="openSettings" title="Settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"></circle>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                </svg>
            </button>
        </div>
    </header>

    <main class="app-main">
        <div class="welcome-message">
            <h2>Welcome to Scout</h2>
            <p>Configure your connections and repositories to get started.</p>
            <button class="btn btn-primary" id="openSettingsFromWelcome">Open Settings</button>
        </div>
    </main>
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
            <select id="assessment-model" class="form-select">
                {{assessmentOptions}}
            </select>
            <p class="help-text">Model used to analyze issues and determine if they're suitable for automated PR creation</p>
        </div>
        <div class="form-group">
            <label for="pr-creation-model">PR Creation Model</label>
            <select id="pr-creation-model" class="form-select">
                {{prCreationOptions}}
            </select>
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
            <button class="btn btn-small btn-primary save-repo" data-repo-id="{{id}}">Save</button>
            <button class="btn btn-small btn-secondary delete-repo" data-repo-id="{{id}}">Delete</button>
        </td>
    </tr>
</script>