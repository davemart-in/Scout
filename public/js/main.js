// Main application logic for Scout

// Global state
const state = {
    settings: null,
    modalOpen: false,
    currentSource: null,
    currentRepoId: null,
    repos: []
};

// Initialize main app
async function initMainApp() {
    try {
        // Load settings
        state.settings = await API.getSettings();

        // Render main app layout
        renderMainApp();

        // Attach event handlers
        attachMainEventHandlers();

        // Load initial data
        await loadInitialData();
    } catch (error) {
        console.error('Failed to initialize app:', error);
        showToast('Failed to initialize app', 'error');
    }
}

// Render main app layout
function renderMainApp() {
    const app = document.getElementById('app');

    // Determine available sources
    const sources = [];
    if (state.settings.has_github) {
        sources.push({ value: 'github', label: 'GitHub' });
    }
    if (state.settings.has_linear) {
        sources.push({ value: 'linear', label: 'Linear' });
    }

    // Build source options
    let sourceOptions = '<option value="">Select source...</option>';
    sources.forEach(source => {
        sourceOptions += `<option value="${source.value}">${source.label}</option>`;
    });

    // Check if we can analyze (have at least one model)
    const canAnalyze = state.settings.available_models && state.settings.available_models.length > 0;

    app.innerHTML = _tmpl('mainApp', {
        sourceOptions,
        repoOptions: '<option value="">Select repository...</option>',
        issueCount: '0 issues',
        fetchButtonClass: 'btn-primary', // Start with fetch as primary
        refreshDisabled: 'disabled', // Disabled initially when no issues
        analyzeButtonClass: 'btn-secondary',
        analyzeDisabled: 'disabled', // Always disabled initially (no issues and/or no models)
        progressDisplay: 'none',
        progressText: '',
        mainContent: _tmpl('emptyState', {
            title: 'Welcome to Scout',
            message: sources.length > 0
                ? 'Select a source and repository to view issues'
                : 'Configure API tokens in settings to get started',
            action: sources.length === 0
                ? '<button class="btn btn-primary" id="openSettingsFromEmpty">Open Settings</button>'
                : ''
        }),
        connectionStatus: 'Initializing...',
        lastSync: ''
    });
}

// Load initial data
async function loadInitialData() {
    // Get repos for the first available source
    const sources = [];
    if (state.settings.has_github) sources.push('github');
    if (state.settings.has_linear) sources.push('linear');

    if (sources.length > 0 && state.settings.repos.length > 0) {
        // Set first source as current
        state.currentSource = sources[0];
        document.getElementById('source-selector').value = state.currentSource;

        // Load repos for this source
        await updateRepoDropdown();

        // If we have repos, select the first one
        const sourceRepos = state.settings.repos.filter(r => r.source === state.currentSource);
        if (sourceRepos.length > 0) {
            state.currentRepoId = sourceRepos[0].id;
            document.getElementById('repo-selector').value = state.currentRepoId;

            // Load issues for the first repo
            await IssuesManager.loadIssues(state.currentRepoId);
        }
    }

    IssuesManager.updateStatusBar();
}

// Update repo dropdown based on selected source
async function updateRepoDropdown() {
    const repoSelector = document.getElementById('repo-selector');
    if (!repoSelector) return;

    // Filter repos by source
    const sourceRepos = state.settings.repos.filter(r => r.source === state.currentSource);

    // Build options
    let options = '<option value="">Select repository...</option>';
    sourceRepos.forEach(repo => {
        options += `<option value="${repo.id}">${repo.name}</option>`;
    });

    repoSelector.innerHTML = options;

    // Clear current repo if it doesn't match the source
    const currentRepo = state.settings.repos.find(r => r.id === state.currentRepoId);
    if (currentRepo && currentRepo.source !== state.currentSource) {
        state.currentRepoId = null;
    }
}

// Attach event handlers for main app
function attachMainEventHandlers() {
    document.addEventListener('click', async (e) => {
        // Settings button
        if (e.target.id === 'openSettings' || e.target.id === 'openSettingsFromEmpty' || e.target.closest('#openSettings')) {
            e.preventDefault();
            openSettingsModal();
        }

        // Fetch issues button
        if (e.target.id === 'fetch-issues') {
            await IssuesManager.syncIssues(state.currentRepoId);
        }

        // Refresh button
        if (e.target.id === 'refresh-issues') {
            await IssuesManager.loadIssues(state.currentRepoId);
        }

        // Analyze all button
        if (e.target.id === 'analyze-all') {
            await IssuesManager.analyzeAll(state.currentRepoId);
        }

        // Create PR button
        if (e.target.classList.contains('create-pr')) {
            const issueId = e.target.dataset.issueId;
            await IssuesManager.createPR(parseInt(issueId));
        }

        // Retry PR button
        if (e.target.classList.contains('retry-pr')) {
            const issueId = e.target.dataset.issueId;
            await IssuesManager.createPR(parseInt(issueId));
        }
    });

    // Source selector change
    document.addEventListener('change', async (e) => {
        if (e.target.id === 'source-selector') {
            state.currentSource = e.target.value;
            await updateRepoDropdown();

            // Clear issues display
            IssuesManager.currentIssues = [];
            IssuesManager.renderEmptyState('Select a repository', 'Choose a repository from the dropdown to view issues');
        }

        if (e.target.id === 'repo-selector') {
            state.currentRepoId = e.target.value ? parseInt(e.target.value) : null;

            if (state.currentRepoId) {
                await IssuesManager.loadIssues(state.currentRepoId);
            } else {
                IssuesManager.currentIssues = [];
                IssuesManager.renderEmptyState('Select a repository', 'Choose a repository from the dropdown to view issues');
                IssuesManager.updateButtonStyles();
            }
        }
    });
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Check if we're on the main page
    const app = document.getElementById('app');
    if (!app) return;

    // Initialize the main app
    initMainApp();
});