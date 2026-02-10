// Settings modal functionality for Scout

// Settings-specific functions
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
    if (!modalContainer) {
        // Create modal container if it doesn't exist
        const container = document.createElement('div');
        container.id = 'modalContainer';
        document.body.appendChild(container);
    }

    const container = document.getElementById('modalContainer');
    if (state.modalOpen) {
        container.innerHTML = renderSettingsModal();
    } else {
        container.innerHTML = '';
    }
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

// Fetch and show repos for selection
async function fetchAndShowRepos(source) {
    showToast(`Fetching ${source} repositories...`, 'info');

    try {
        const result = await API.fetchRepos(source);

        if (!result.repos || result.repos.length === 0) {
            showToast('No repositories found', 'warning');
            return;
        }

        // Get existing repos to filter out already added ones
        const existingRepos = state.settings.repos
            .filter(r => r.source === source)
            .map(r => r.source_id);

        // Filter out already added repos
        const availableRepos = result.repos.filter(
            repo => !existingRepos.includes(repo.source_id)
        );

        if (availableRepos.length === 0) {
            showToast('All repositories have already been added', 'info');
            return;
        }

        // Build checkboxes HTML
        const repoCheckboxes = availableRepos.map(repo => `
            <label class="repo-checkbox">
                <input type="checkbox" value="${repo.source_id}" data-name="${repo.name}">
                <span>${repo.name}</span>
            </label>
        `).join('');

        // Show selection modal
        const selectionModal = _tmpl('repoSelectionModal', {
            repoCheckboxes
        });

        // Add to page
        const tempContainer = document.createElement('div');
        tempContainer.id = 'repoSelectionContainer';
        tempContainer.innerHTML = selectionModal;
        document.body.appendChild(tempContainer);

        // Store source for later
        state.currentSource = source;

    } catch (error) {
        console.error('Failed to fetch repos:', error);
    }
}

// Attach settings event handlers
function attachSettingsEventHandlers() {
    // Track changes to repository fields
    document.addEventListener('input', (e) => {
        const repoRow = e.target.closest('tr[data-repo-id]');
        if (repoRow && (
            e.target.classList.contains('repo-local-path') ||
            e.target.classList.contains('repo-branch')
        )) {
            const saveBtn = repoRow.querySelector('.save-repo');
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    });

    // Track changes to checkboxes
    document.addEventListener('change', (e) => {
        const repoRow = e.target.closest('tr[data-repo-id]');
        if (repoRow && e.target.classList.contains('repo-auto-pr')) {
            const saveBtn = repoRow.querySelector('.save-repo');
            if (saveBtn) {
                saveBtn.disabled = false;
            }
        }
    });

    document.addEventListener('click', async (e) => {
        // Close modal
        if (e.target.id === 'closeModal' || e.target.id === 'settingsModalOverlay') {
            if (e.target.id === 'settingsModalOverlay' && e.target !== document.getElementById('settingsModalOverlay')) {
                return;
            }
            closeSettingsModal();
            // Reload main app to reflect any changes
            await initMainApp();
        }

        // Save repo
        if (e.target.classList.contains('save-repo')) {
            const saveBtn = e.target;
            const repoId = saveBtn.dataset.repoId;
            const row = saveBtn.closest('tr');
            const localPath = row.querySelector('.repo-local-path').value;
            const branch = row.querySelector('.repo-branch').value;
            const autoPr = row.querySelector('.repo-auto-pr').checked;

            await API.saveRepo(repoId, localPath, branch, autoPr);
            showToast('Repository saved successfully', 'success');

            // Disable the save button after successful save
            saveBtn.disabled = true;

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
            fetchAndShowRepos(source);
        }

        // Test connection
        if (e.target.classList.contains('test-connection')) {
            const modelType = e.target.dataset.modelType;
            const selectId = modelType === 'assessment' ? 'assessment-model' : 'pr-creation-model';
            const selectedModel = document.getElementById(selectId)?.value;

            if (selectedModel) {
                showToast(`Testing connection to ${selectedModel}... (will be implemented in Prompt 7)`, 'info');
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

        // Repo selection modal handlers
        if (e.target.id === 'closeRepoSelection' || e.target.id === 'cancelRepoSelection' || e.target.id === 'repoSelectionOverlay') {
            if (e.target.id === 'repoSelectionOverlay' && e.target !== document.getElementById('repoSelectionOverlay')) {
                return;
            }
            const container = document.getElementById('repoSelectionContainer');
            if (container) container.remove();
        }

        // Add selected repos
        if (e.target.id === 'addSelectedRepos') {
            const checkboxes = document.querySelectorAll('#repoSelectionContainer input[type="checkbox"]:checked');
            if (checkboxes.length === 0) {
                showToast('Please select at least one repository', 'warning');
                return;
            }

            let addedCount = 0;
            for (const checkbox of checkboxes) {
                try {
                    await API.addRepo(
                        state.currentSource,
                        checkbox.value,
                        checkbox.dataset.name
                    );
                    addedCount++;
                } catch (error) {
                    console.error(`Failed to add repo ${checkbox.dataset.name}:`, error);
                }
            }

            if (addedCount > 0) {
                showToast(`Added ${addedCount} repositor${addedCount === 1 ? 'y' : 'ies'}`, 'success');
                await loadSettings();
                updateSettingsModal();
            }

            // Close selection modal
            const container = document.getElementById('repoSelectionContainer');
            if (container) container.remove();
        }
    });
}

// Initialize settings handlers on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    attachSettingsEventHandlers();
});