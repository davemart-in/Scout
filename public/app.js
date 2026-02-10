// Scout Frontend

// Global state
let state = {
    settings: null,
    modalOpen: false,
    currentSource: null,
    currentRepoId: null
};

// Template system
const templates = {};

// Load template from DOM and cache it
function loadTemplate(name) {
    const element = document.getElementById(`tmpl-${name}`);
    if (!element) {
        throw new Error(`Template "tmpl-${name}" not found in DOM`);
    }
    return element.innerHTML;
}

// Simple template engine with variable replacement
function renderTemplate(template, data = {}) {
    return template.replace(/\{\{(\w+)\}\}/g, (match, key) => {
        return data.hasOwnProperty(key) ? data[key] : match;
    });
}

// Main template function
function _tmpl(name, data = {}) {
    if (!templates[name]) {
        templates[name] = loadTemplate(name);
    }
    return renderTemplate(templates[name], data);
}

// API functions
const API = {
    async getSettings() {
        try {
            const response = await fetch('/api/settings');
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to load settings');
        } catch (error) {
            showToast('Failed to load settings: ' + error.message, 'error');
            throw error;
        }
    },

    // Token management removed - tokens are now configured in .env file

    async saveModelPreferences(assessmentModel, prCreationModel) {
        try {
            const response = await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_model_preferences',
                    assessment_model: assessmentModel,
                    pr_creation_model: prCreationModel
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to save model preferences');
        } catch (error) {
            showToast('Failed to save model preferences: ' + error.message, 'error');
            throw error;
        }
    },

    async saveRepo(id, localPath, defaultBranch, autoCreatePr) {
        try {
            const response = await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'save_repo',
                    id: id,
                    local_path: localPath,
                    default_branch: defaultBranch,
                    auto_create_pr: autoCreatePr
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to save repository');
        } catch (error) {
            showToast('Failed to save repository: ' + error.message, 'error');
            throw error;
        }
    },

    async deleteRepo(id) {
        try {
            const response = await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete_repo',
                    id: id
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to delete repository');
        } catch (error) {
            showToast('Failed to delete repository: ' + error.message, 'error');
            throw error;
        }
    },

    async fetchRepos(source) {
        try {
            const response = await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'fetch_repos',
                    source: source
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to fetch repositories');
        } catch (error) {
            showToast('Failed to fetch repositories: ' + error.message, 'error');
            throw error;
        }
    }
};

// UI Helper functions
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);

    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);

    // Remove after 5 seconds
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// Helper functions for rendering complex templates

function renderApiKeysAlert() {
    const { has_github, has_linear, has_openai, has_anthropic } = state.settings;

    const missing = [];
    if (!has_github && !has_linear) {
        missing.push('source connection (GitHub or Linear)');
    }
    if (!has_openai && !has_anthropic) {
        missing.push('AI model API key (OpenAI or Anthropic)');
    }

    if (missing.length === 0) return '';

    const missingKeys = [];
    if (!has_github) missingKeys.push('GITHUB_TOKEN');
    if (!has_linear) missingKeys.push('LINEAR_TOKEN');
    if (!has_openai) missingKeys.push('OPENAI_KEY');
    if (!has_anthropic) missingKeys.push('ANTHROPIC_KEY');

    return _tmpl('apiKeysAlert', {
        missingKeysMessage: `Missing ${missing.join(' and ')}. Scout needs at least one source and one AI provider configured.`,
        missingKeysExample: missingKeys.map(key => `${key}=your_${key.toLowerCase().replace('_', '_')}_here`).join('<br>')
    });
}

function renderModelSelection() {
    const { available_models, assessment_model, pr_creation_model } = state.settings;

    if (!available_models || available_models.length === 0) {
        return _tmpl('noModels', {});
    }

    // Build options for both dropdowns
    const assessmentOptions = available_models.map(model =>
        `<option value="${model.value}" ${model.value === assessment_model ? 'selected' : ''}>
            ${model.label} (${model.provider})
        </option>`
    ).join('');

    const prCreationOptions = available_models.map(model =>
        `<option value="${model.value}" ${model.value === pr_creation_model ? 'selected' : ''}>
            ${model.label} (${model.provider})
        </option>`
    ).join('');

    return _tmpl('modelSelection', {
        assessmentOptions,
        prCreationOptions
    });
}

function renderRepoRow(repo) {
    return _tmpl('repoRow', {
        id: repo.id,
        name: repo.name,
        source: repo.source,
        localPath: repo.local_path || '',
        defaultBranch: repo.default_branch || 'main',
        autoCreatePr: repo.auto_create_pr ? 'checked' : ''
    });
}

function renderSettingsModal() {
    if (!state.settings) return '';

    const { repos, has_github, has_linear } = state.settings;

    // Render API keys alert (if needed)
    const apiKeysAlert = renderApiKeysAlert();

    // Render model selection
    const modelSelectionContent = renderModelSelection();

    // Render repo actions - only show buttons if we have the corresponding API keys
    const repoActions = [];
    if (has_github) {
        repoActions.push('<button class="btn btn-secondary fetch-repos" data-source="github">Fetch GitHub Repos</button>');
    }
    if (has_linear) {
        repoActions.push('<button class="btn btn-secondary fetch-repos" data-source="linear">Fetch Linear Teams</button>');
    }
    const repoActionsHtml = repoActions.join('');

    // Render repo rows
    const repoRows = repos.map(repo => renderRepoRow(repo)).join('');

    // Empty state
    const emptyState = repos.length === 0 ? '<p class="empty-state">No repositories configured yet</p>' : '';

    return _tmpl('settingsModal', {
        modalClass: state.modalOpen ? 'show' : '',
        apiKeysAlert,
        modelSelectionContent,
        repoActions: repoActionsHtml,
        repoRows,
        emptyState
    });
}

// Event handlers
function attachEventHandlers() {
    // Settings button
    document.addEventListener('click', async (e) => {
        // Open settings
        if (e.target.id === 'openSettings' || e.target.id === 'openSettingsFromWelcome' || e.target.closest('#openSettings')) {
            e.preventDefault();
            openSettingsModal();
        }

        // Close modal
        if (e.target.id === 'closeModal' || e.target.id === 'settingsModalOverlay') {
            if (e.target.id === 'settingsModalOverlay' && e.target !== document.getElementById('settingsModalOverlay')) {
                return;
            }
            closeSettingsModal();
        }

        // Token actions removed - tokens are now configured in .env file

        // Save repo
        if (e.target.classList.contains('save-repo')) {
            const repoId = e.target.dataset.repoId;
            const row = e.target.closest('tr');
            const localPath = row.querySelector('.repo-local-path').value;
            const branch = row.querySelector('.repo-branch').value;
            const autoPr = row.querySelector('.repo-auto-pr').checked;

            await API.saveRepo(repoId, localPath, branch, autoPr);
            showToast('Repository saved successfully', 'success');
            await loadSettings();
            updateSettingsModal();
        }

        // Delete repo
        if (e.target.classList.contains('delete-repo')) {
            const repoId = e.target.dataset.repoId;
            if (confirm('Are you sure you want to delete this repository?')) {
                await API.deleteRepo(repoId);
                showToast('Repository deleted successfully', 'success');
                await loadSettings();
                updateSettingsModal();
            }
        }

        // Fetch repos
        if (e.target.classList.contains('fetch-repos')) {
            const source = e.target.dataset.source;
            showToast(`Fetching ${source} repositories will be implemented in Prompt 4/5`, 'info');
        }

        // Test connection
        if (e.target.classList.contains('test-connection')) {
            const modelType = e.target.dataset.modelType;
            const selectId = modelType === 'assessment' ? 'assessment-model' : 'pr-creation-model';
            const selectedModel = document.getElementById(selectId)?.value;

            if (selectedModel) {
                showToast(`Testing connection to ${selectedModel}... (will be implemented in Prompt 5)`, 'info');
            } else {
                showToast('Please select a model first', 'warning');
            }
        }

        // Save model preferences
        if (e.target.id === 'save-model-preferences') {
            const assessmentModel = document.getElementById('assessment-model')?.value;
            const prCreationModel = document.getElementById('pr-creation-model')?.value;

            if (assessmentModel && prCreationModel) {
                await API.saveModelPreferences(assessmentModel, prCreationModel);
                showToast('Model preferences saved successfully', 'success');
                await loadSettings();
                updateSettingsModal();
            }
        }
    });
}

async function openSettingsModal() {
    state.modalOpen = true;
    await loadSettings();
    updateSettingsModal();
}

function closeSettingsModal() {
    state.modalOpen = false;
    updateSettingsModal();
}

async function loadSettings() {
    state.settings = await API.getSettings();
}

function updateSettingsModal() {
    const modalContainer = document.getElementById('modalContainer');
    if (modalContainer) {
        modalContainer.innerHTML = renderSettingsModal();
    }
}

// Initialize app
async function init() {
    console.log('Scout initialized');

    // Render main app
    const app = document.getElementById('app');
    app.innerHTML = _tmpl('mainApp');

    // Add modal container
    const modalContainer = document.createElement('div');
    modalContainer.id = 'modalContainer';
    document.body.appendChild(modalContainer);

    // Attach event handlers
    attachEventHandlers();

    // Load initial settings
    try {
        await loadSettings();
    } catch (error) {
        console.error('Failed to load initial settings:', error);
    }
}

// Start the app when DOM is ready
document.addEventListener('DOMContentLoaded', init);