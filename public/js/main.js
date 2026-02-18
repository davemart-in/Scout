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
        // Try to restore saved source from localStorage
        const savedSource = localStorage.getItem('scout_selected_source');
        const savedRepoId = localStorage.getItem('scout_selected_repo');

        // Use saved source if it's still available, otherwise use first source
        if (savedSource && sources.includes(savedSource)) {
            state.currentSource = savedSource;
        } else {
            state.currentSource = sources[0];
        }
        document.getElementById('source-selector').value = state.currentSource;

        // Load repos for this source
        await updateRepoDropdown();

        // Check if saved repo is still valid for current source
        const sourceRepos = state.settings.repos.filter(r => r.source === state.currentSource);
        let repoToSelect = null;

        if (savedRepoId) {
            // Try to find the saved repo in the current source's repos
            const savedRepo = sourceRepos.find(r => r.id === parseInt(savedRepoId));
            if (savedRepo) {
                repoToSelect = savedRepo.id;
            }
        }

        // If no saved repo or it's not valid, use the first repo
        if (!repoToSelect && sourceRepos.length > 0) {
            repoToSelect = sourceRepos[0].id;
        }

        if (repoToSelect) {
            state.currentRepoId = repoToSelect;
            document.getElementById('repo-selector').value = state.currentRepoId;

            // Load issues for the selected repo (silently, no notification)
            await IssuesManager.loadIssues(state.currentRepoId, false);
            // Start polling for this repo
            IssuesManager.startPolling();
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

        // Issues tabs
        if (e.target.classList.contains('issues-tab-btn')) {
            const tab = e.target.dataset.issuesTab;
            IssuesManager.setTab(tab);
        }

        // Create PR button
        if (e.target.classList.contains('create-pr')) {
            e.preventDefault();
            e.stopPropagation();
            const issueId = e.target.dataset.issueId;
            if (issueId) {
                await IssuesManager.createPR(parseInt(issueId));
            }
        }

        // Retry PR button
        if (e.target.classList.contains('retry-pr')) {
            const issueId = e.target.dataset.issueId;
            await IssuesManager.createPR(parseInt(issueId));
        }

        // Cancel in-progress PR run
        if (e.target.classList.contains('cancel-pr-link')) {
            e.preventDefault();
            e.stopPropagation();

            const issueId = parseInt(e.target.dataset.issueId, 10);
            if (!issueId) return;

            const confirmed = confirm('Cancel this in-progress run?');
            if (!confirmed) return;

            try {
                await API.cancelPR(issueId);
                showToast('Run canceled', 'success');
                await IssuesManager.loadIssues(state.currentRepoId, false);
            } catch (error) {
                showToast(`Failed to cancel run: ${error.message}`, 'error');
            }
        }
    });

    // Source selector change
    document.addEventListener('change', async (e) => {
        if (e.target.id === 'source-selector') {
            state.currentSource = e.target.value;
            IssuesManager.setTab('active');
            // Save to localStorage
            localStorage.setItem('scout_selected_source', e.target.value);
            await updateRepoDropdown();

            // Clear issues display
            IssuesManager.currentIssues = [];
            IssuesManager.renderEmptyState('Select a repository', 'Choose a repository from the dropdown to view issues');
        }

        if (e.target.id === 'repo-selector') {
            state.currentRepoId = e.target.value ? parseInt(e.target.value) : null;
            IssuesManager.setTab('active');
            // Save to localStorage
            if (state.currentRepoId) {
                localStorage.setItem('scout_selected_repo', e.target.value);
            } else {
                localStorage.removeItem('scout_selected_repo');
            }

            if (state.currentRepoId) {
                // Load issues silently when changing repos
                await IssuesManager.loadIssues(state.currentRepoId, false);
                // Start polling for this repo
                IssuesManager.startPolling();
            } else {
                IssuesManager.currentIssues = [];
                IssuesManager.renderEmptyState('Select a repository', 'Choose a repository from the dropdown to view issues');
                IssuesManager.updateButtonStyles();
                // Stop polling when no repo selected
                IssuesManager.stopPolling();
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
