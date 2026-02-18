// Issues management for Scout

const IssuesManager = {
    currentIssues: [],
    activeTab: 'active',
    hasMoreIssues: true,
    lastSync: null,
    lastUpdateTimestamp: null,
    pollingInterval: null,
    prDetectionInterval: null,

    async loadIssues(repoId, showNotification = true) {
        if (!repoId) {
            this.renderEmptyState('No repository selected', 'Select a repository from the dropdown above');
            return;
        }

        // Show loading state only when explicitly refreshing
        const refreshButton = document.getElementById('refresh-issues');
        let originalHTML = '';
        if (showNotification && refreshButton) {
            originalHTML = refreshButton.innerHTML;
            refreshButton.innerHTML = '<span class="spinner"></span> Refreshing...';
            refreshButton.disabled = true;
        }

        try {
            const result = await API.fetchIssues(repoId, 5000);
            this.currentIssues = result.issues || [];
            this.hasMoreIssues = result.has_more !== false;
            this.lastSync = new Date();
            this.renderIssues();
            this.updateStatusBar();
            this.updateButtonStyles();
            if (showNotification) {
                showToast(`Loaded ${this.currentIssues.length} issues`, 'success');
            }
        } catch (error) {
            if (showNotification) {
                showToast('Failed to load issues', 'error');
            }
            this.renderEmptyState('Failed to load issues', error.message);
        } finally {
            // Restore refresh button
            if (showNotification && refreshButton && originalHTML) {
                refreshButton.innerHTML = originalHTML;
                refreshButton.disabled = this.currentIssues.length === 0;
            }
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
            const fetchedCount = result.fetched_count ?? result.total ?? 0;
            if ((result.new ?? 0) > 0) {
                showToast(`Fetched ${fetchedCount} issues (${result.new} new, ${result.updated ?? 0} updated)`, 'success');
            } else if (fetchedCount > 0) {
                showToast(`Fetched ${fetchedCount} issues, but no new issues were added`, 'info');
            } else {
                showToast(result.message || 'No more issues to fetch', 'info');
            }
            this.hasMoreIssues = result.has_more !== false;

            // Reload issues after sync
            await this.loadIssues(repoId, false);
        } catch (error) {
            showToast('Failed to sync issues', 'error');
        }
    },

    getTooComplexIssues() {
        return this.currentIssues.filter(issue => issue.assessment === 'too_complex');
    },

    getActiveIssues() {
        return this.currentIssues.filter(issue => issue.assessment !== 'too_complex');
    },

    getVisibleIssues() {
        return this.activeTab === 'too_complex' ? this.getTooComplexIssues() : this.getActiveIssues();
    },

    setTab(tab) {
        if (!['active', 'too_complex'].includes(tab)) return;
        this.activeTab = tab;
        this.renderIssues();
        this.updateButtonStyles();
    },

    renderIssues() {
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) return;

        if (this.currentIssues.length === 0) {
            this.renderEmptyState('No issues found', 'Fetch issues from the source to get started');
            return;
        }

        const activeIssues = this.getActiveIssues();
        const tooComplexIssues = this.getTooComplexIssues();
        const visibleIssues = this.getVisibleIssues();

        // Build issue rows
        const issueRows = visibleIssues.map(issue => this.renderIssueRow(issue)).join('');

        mainContent.innerHTML = _tmpl('issuesTable', {
            issueRows: issueRows || '<tr><td colspan="6" class="empty-state">No issues in this tab</td></tr>',
            activeCount: activeIssues.length,
            tooComplexCount: tooComplexIssues.length,
            activeTabClass: this.activeTab === 'active' ? 'active' : '',
            tooComplexTabClass: this.activeTab === 'too_complex' ? 'active' : ''
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
            issueId: issue.id,
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

        const visibleOpenCount = this.getVisibleIssues().filter(i => i.status === 'open').length;
        countElement.textContent = `${visibleOpenCount} open issue${visibleOpenCount !== 1 ? 's' : ''}`;
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

        const visiblePendingIssueIds = this.getVisibleIssues()
            .filter(i => i.assessment === 'pending')
            .map(i => parseInt(i.id, 10));
        const initialPendingCount = visiblePendingIssueIds.length;
        if (initialPendingCount === 0) {
            showToast('No pending issues to analyze', 'info');
            return;
        }

        showToast(`Starting analysis of ${initialPendingCount} issues...`, 'info');

        // Update progress indicator
        const progressIndicator = document.querySelector('.progress-indicator');
        if (progressIndicator) {
            progressIndicator.style.display = 'inline';
            progressIndicator.textContent = `Analyzing ${initialPendingCount} issues...`;
        }

        let totalAnalyzed = 0;
        let remaining = initialPendingCount;
        let batchCount = 0;

        try {
            // Keep analyzing in batches until all are done
            while (remaining > 0) {
                batchCount++;

                // Update progress indicator
                if (progressIndicator) {
                    progressIndicator.textContent = `Analyzing: ${totalAnalyzed}/${initialPendingCount} completed...`;
                }

                const result = await API.analyzeIssues(repoId, visiblePendingIssueIds);

                if (result.analyzed > 0) {
                    totalAnalyzed += result.analyzed;
                    remaining = result.remaining;

                    // Reload issues to show new assessments (suppress toast notification)
                    await this.loadIssues(repoId, false);

                    // If there are more to analyze, wait a moment before continuing
                    // to avoid overwhelming the API
                    if (remaining > 0) {
                        await new Promise(resolve => setTimeout(resolve, 1000));
                    }
                } else {
                    // No issues were analyzed in this batch, stop
                    break;
                }
            }

            showToast(`Analysis complete! Analyzed ${totalAnalyzed} issues in ${batchCount} batch${batchCount > 1 ? 'es' : ''}`, 'success');

        } catch (error) {
            showToast(`Analysis stopped after ${totalAnalyzed} issues: ${error.message || 'Failed to analyze'}`, 'error');
        } finally {
            // Hide progress indicator
            if (progressIndicator) {
                progressIndicator.style.display = 'none';
            }
        }
    },

    showPRContextModal(issueId) {
        // Convert to number and compare, handling both string and number IDs
        const issue = this.currentIssues.find(i => parseInt(i.id) === parseInt(issueId));
        if (!issue) {
            showToast('Issue not found', 'error');
            return;
        }

        // Get PR creation model from settings
        const model = state.settings?.pr_creation_model || 'claude-opus-4-6';

        // Build summary HTML if summary exists
        const summaryHtml = issue.summary
            ? `<div class="issue-summary-text">${issue.summary}</div>`
            : '';

        // Prepare modal data
        const modalData = {
            issueId: issue.id,
            source: issue.source,
            sourceId: issue.source_id,
            title: issue.title,
            summaryHtml: summaryHtml,
            model: model
        };

        // Render and show modal
        try {
            const modalHTML = _tmpl('prContextModal', modalData);
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        } catch (error) {
            showToast('Error showing PR context modal', 'error');
            return;
        }

        // Add event listeners
        const overlay = document.getElementById('prContextModalOverlay');

        // Show the modal with animation
        if (overlay) {
            // Prevent body scroll
            document.body.classList.add('modal-open');
            // Force a reflow to ensure the initial state is applied
            overlay.offsetHeight;
            // Add show class to make it visible
            overlay.classList.add('show');
            const modal = overlay.querySelector('.modal');
            if (modal) {
                modal.style.opacity = '1';
                modal.style.transform = 'scale(1) translateY(0)';
            }
        }
        const closeBtn = document.getElementById('closePrContext');
        const cancelBtn = document.getElementById('cancelPrContext');
        const confirmBtn = document.getElementById('confirmPrContext');
        const contextTextarea = document.getElementById('prContext');

        if (!overlay) {
            return;
        }

        const closeModal = () => {
            document.body.classList.remove('modal-open');
            overlay.remove();
        };

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });

        // Handle "Change in Settings" link
        const changePrModelLink = document.getElementById('changePrModel');
        if (changePrModelLink) {
            changePrModelLink.addEventListener('click', async (e) => {
                e.preventDefault();
                closeModal();
                // Open settings modal
                await openSettingsModal();
            });
        }

        confirmBtn.addEventListener('click', async () => {
            const context = contextTextarea.value.trim();
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Launching...';

            // Update UI immediately to show launching status
            const issueRow = document.querySelector(`tr[data-issue-id="${issueId}"]`);
            if (issueRow) {
                const actionCell = issueRow.querySelector('.action-cell');
                if (actionCell) {
                    actionCell.innerHTML = '<span class="spinner"></span> <span class="text-muted">Launching...</span>';
                }
            }

            try {
                const result = await API.createPR(issueId, context);
                showToast('Claude Code launched successfully', 'success');
                closeModal();

                // Update UI to show in-progress status
                // Update the issue in our local state
                const issueIndex = this.currentIssues.findIndex(i => parseInt(i.id) === parseInt(issueId));
                if (issueIndex >= 0) {
                    this.currentIssues[issueIndex].pr_status = 'in_progress';
                }

                // Update UI to show in-progress status
                if (issueRow) {
                    const actionCell = issueRow.querySelector('.action-cell');
                    if (actionCell) {
                        const issueData = issueIndex >= 0
                            ? this.currentIssues[issueIndex]
                            : { id: issueId, pr_status: 'in_progress' };
                        actionCell.innerHTML = createActionContent(issueData);
                    }
                    // Add a subtle highlight animation
                    issueRow.classList.add('row-highlight');
                    setTimeout(() => issueRow.classList.remove('row-highlight'), 1000);
                }

                // Reload issues after a short delay
                setTimeout(() => this.loadIssues(state.currentRepoId, false), 2000);
            } catch (error) {
                showToast(`Failed to launch Claude Code: ${error.message}`, 'error');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Launch Claude Code';

                // Restore the original button if there was an error
                if (issueRow) {
                    const actionCell = issueRow.querySelector('.action-cell');
                    if (actionCell) {
                        actionCell.innerHTML = `<button class="btn btn-primary btn-small create-pr" data-issue-id="${issueId}">Create PR</button>`;
                    }
                }
            }
        });

        // Focus on textarea
        contextTextarea.focus();
    },

    createPR(issueId) {
        // Show the context modal instead of directly creating PR
        this.showPRContextModal(issueId);
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

        fetchButton.textContent = this.hasMoreIssues ? 'Fetch 50 Issues' : 'No more issues';
        fetchButton.disabled = !this.hasMoreIssues;

        // Analyze button is disabled if no models available OR no issues loaded
        const canAnalyze = state.settings &&
                          state.settings.available_models &&
                          state.settings.available_models.length > 0;
        const hasVisiblePending = this.getVisibleIssues().some(i => i.assessment === 'pending');
        analyzeButton.disabled = !canAnalyze || this.currentIssues.length === 0 || !hasVisiblePending || this.activeTab !== 'active';
    },

    // Start polling for issue updates
    startPolling() {
        // Stop any existing polling
        this.stopPolling();

        // Start polling every 10 seconds
        this.pollingInterval = setInterval(async () => {
            if (state.currentRepoId) {
                await this.checkForUpdates(state.currentRepoId);
            }
        }, 10000);

        // Start PR detection every 60 seconds
        this.prDetectionInterval = setInterval(async () => {
            if (state.currentRepoId) {
                await this.checkForPRs(state.currentRepoId);
            }
        }, 60000);
    },

    // Stop polling
    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
        if (this.prDetectionInterval) {
            clearInterval(this.prDetectionInterval);
            this.prDetectionInterval = null;
        }
    },

    // Check for issue updates
    async checkForUpdates(repoId) {
        try {
            const params = new URLSearchParams({
                repo_id: repoId,
                check_updates: 1,
                per_page: 5000
            });

            if (this.lastUpdateTimestamp) {
                params.append('last_timestamp', this.lastUpdateTimestamp);
            }

            const response = await fetch(`/api/issues.php?${params}`);
            const result = await response.json();

            // Only update if data has changed
            if (result.last_updated && result.last_updated !== this.lastUpdateTimestamp) {
                this.lastUpdateTimestamp = result.last_updated;
                this.currentIssues = result.issues || [];
                this.hasMoreIssues = result.has_more !== false;
                this.renderIssues();
                this.updateStatusBar();
                this.updateButtonStyles();
            }
        } catch (error) {
            console.error('Failed to check for updates:', error);
        }
    },

    // Check for PRs on GitHub
    async checkForPRs(repoId) {
        try {
            const response = await fetch('/api/issues.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'check_prs',
                    repo_id: repoId
                })
            });

            const result = await response.json();

            // If PRs were detected, reload issues
            if (result.updated && result.updated > 0) {
                await this.loadIssues(repoId, false);
                showToast(`Updated status for ${result.updated} issue${result.updated !== 1 ? 's' : ''}`, 'info');
            }
        } catch (error) {
            console.error('Failed to check for PRs:', error);
        }
    }
};
