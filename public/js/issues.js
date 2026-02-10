// Issues management for Scout

const IssuesManager = {
    currentIssues: [],
    lastSync: null,

    async loadIssues(repoId) {
        if (!repoId) {
            this.renderEmptyState('No repository selected', 'Select a repository from the dropdown above');
            return;
        }

        try {
            const result = await API.fetchIssues(repoId);
            this.currentIssues = result.issues || [];
            this.lastSync = new Date();
            this.renderIssues();
            this.updateStatusBar();
            this.updateButtonStyles();
        } catch (error) {
            showToast('Failed to load issues', 'error');
            this.renderEmptyState('Failed to load issues', error.message);
        }
    },

    async syncIssues(repoId) {
        if (!repoId) {
            showToast('No repository selected', 'warning');
            return;
        }

        showToast('Fetching issues from source...', 'info');

        try {
            const result = await API.syncIssues(repoId);
            showToast(`Synced ${result.new} new, ${result.updated} updated issues`, 'success');

            // Reload issues after sync
            await this.loadIssues(repoId);
        } catch (error) {
            showToast('Failed to sync issues', 'error');
        }
    },

    renderIssues() {
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) return;

        if (this.currentIssues.length === 0) {
            this.renderEmptyState('No issues found', 'Fetch issues from the source to get started');
            return;
        }

        // Build issue rows
        const issueRows = this.currentIssues.map(issue => this.renderIssueRow(issue)).join('');

        mainContent.innerHTML = _tmpl('issuesTable', {
            issueRows
        });

        this.updateIssueCount();
    },

    renderIssueRow(issue) {
        // Format the source ID (e.g., "#123" for GitHub, "TEAM-45" for Linear)
        const sourceId = issue.source === 'github'
            ? `#${issue.source_id}`
            : issue.source_id;

        // Format summary
        let summary = issue.summary || '';
        if (!summary && issue.assessment === 'pending') {
            summary = '<em class="text-muted">Pending analysis...</em>';
        }

        return _tmpl('issueRow', {
            sourceUrl: issue.source_url || '#',
            title: issue.title || 'Untitled',
            sourceId: sourceId,
            summary: summary,
            labels: formatLabels(issue.labels),
            priority: formatPriority(issue.priority),
            createdRelative: relativeTime(issue.created_at),
            createdFull: formatDate(issue.created_at),
            assessmentBadge: createAssessmentBadge(issue.assessment),
            actionContent: createActionContent(issue)
        });
    },

    renderEmptyState(title, message, action = '') {
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) return;

        mainContent.innerHTML = _tmpl('emptyState', {
            title,
            message,
            action
        });
    },

    updateIssueCount() {
        const countElement = document.querySelector('.issue-count');
        if (!countElement) return;

        const openCount = this.currentIssues.filter(i => i.status === 'open').length;
        countElement.textContent = `${openCount} open issue${openCount !== 1 ? 's' : ''}`;
    },

    updateStatusBar() {
        // Update connection status
        const connectionElement = document.querySelector('.connection-status');
        if (connectionElement) {
            const hasTokens = state.settings &&
                (state.settings.has_github || state.settings.has_linear);
            connectionElement.textContent = hasTokens ? 'Connected' : 'No API tokens configured';
            connectionElement.className = `connection-status ${hasTokens ? 'connected' : 'disconnected'}`;
        }

        // Update last sync
        const syncElement = document.querySelector('.last-sync');
        if (syncElement && this.lastSync) {
            syncElement.textContent = `Last updated: ${relativeTime(this.lastSync)}`;
        }
    },

    async analyzeAll(repoId) {
        if (!repoId) {
            showToast('No repository selected', 'warning');
            return;
        }

        const pendingCount = this.currentIssues.filter(i => i.assessment === 'pending').length;
        if (pendingCount === 0) {
            showToast('No pending issues to analyze', 'info');
            return;
        }

        showToast(`Analyzing ${pendingCount} issues...`, 'info');

        // Update progress indicator
        const progressIndicator = document.querySelector('.progress-indicator');
        if (progressIndicator) {
            progressIndicator.style.display = 'inline';
            progressIndicator.textContent = `Analyzing ${pendingCount} issues...`;
        }

        try {
            const result = await API.analyzeIssues(repoId);
            showToast(`Analyzed ${result.analyzed} issues`, 'success');

            // Reload issues to show new assessments
            await this.loadIssues(repoId);
        } catch (error) {
            showToast('Failed to analyze issues', 'error');
        } finally {
            // Hide progress indicator
            if (progressIndicator) {
                progressIndicator.style.display = 'none';
            }
        }
    },

    async createPR(issueId) {
        const issue = this.currentIssues.find(i => i.id === issueId);
        if (!issue) {
            showToast('Issue not found', 'error');
            return;
        }

        showToast(`Creating PR for: ${issue.title}`, 'info');

        try {
            const result = await API.createPR(issueId);
            showToast('PR creation started', 'success');

            // Reload issues to show updated status
            await this.loadIssues(state.currentRepoId);
        } catch (error) {
            showToast('Failed to create PR', 'error');
        }
    },

    updateButtonStyles() {
        const fetchButton = document.getElementById('fetch-issues');
        const refreshButton = document.getElementById('refresh-issues');
        const analyzeButton = document.getElementById('analyze-all');

        if (!fetchButton || !analyzeButton || !refreshButton) return;

        // If we have issues, make Analyze All primary, otherwise Fetch Issues is primary
        if (this.currentIssues.length > 0) {
            fetchButton.className = 'btn btn-secondary';
            analyzeButton.className = 'btn btn-primary';
            refreshButton.disabled = false;
        } else {
            fetchButton.className = 'btn btn-primary';
            analyzeButton.className = 'btn btn-secondary';
            refreshButton.disabled = true;
        }

        // Analyze button is disabled if no models available OR no issues loaded
        const canAnalyze = state.settings &&
                          state.settings.available_models &&
                          state.settings.available_models.length > 0;
        analyzeButton.disabled = !canAnalyze || this.currentIssues.length === 0;
    }
};