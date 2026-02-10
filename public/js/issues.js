// Issues management for Scout

const IssuesManager = {
    currentIssues: [],
    lastSync: null,

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
            const result = await API.fetchIssues(repoId);
            this.currentIssues = result.issues || [];
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

        const initialPendingCount = this.currentIssues.filter(i => i.assessment === 'pending').length;
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

                const result = await API.analyzeIssues(repoId);

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
        const model = state.settings?.pr_creation_model || 'claude-3-5-sonnet-20241022';

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

            try {
                const result = await API.createPR(issueId, context);
                showToast('Claude Code launched successfully', 'success');
                closeModal();

                // Update UI immediately to show in-progress status
                const row = document.querySelector(`[data-issue-id="${issueId}"]`);
                if (row) {
                    const actionCell = row.querySelector('.action-cell');
                    if (actionCell) {
                        actionCell.innerHTML = '<span class="spinner"></span> In Progress';
                    }
                }

                // Reload issues after a short delay
                setTimeout(() => this.loadIssues(state.currentRepoId, false), 2000);
            } catch (error) {
                showToast(`Failed to launch Claude Code: ${error.message}`, 'error');
                confirmBtn.disabled = false;
                confirmBtn.textContent = 'Launch Claude Code';
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

        // Analyze button is disabled if no models available OR no issues loaded
        const canAnalyze = state.settings &&
                          state.settings.available_models &&
                          state.settings.available_models.length > 0;
        analyzeButton.disabled = !canAnalyze || this.currentIssues.length === 0;
    }
};