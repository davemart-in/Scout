// API functions for Scout - using common utilities

const API = {
    async getSettings() {
        return apiGet('/api/settings');
    },

    async fetchIssues(repoId, perPage = 5000) {
        return apiGet('/api/issues', { repo_id: repoId, per_page: perPage });
    },

    async checkForUpdates(repoId, lastTimestamp, perPage = 5000) {
        return apiGet('/api/issues', {
            repo_id: repoId,
            check_updates: 1,
            last_timestamp: lastTimestamp,
            per_page: perPage
        });
    },

    async syncIssues(repoId) {
        return apiPost('/api/issues', {
            action: 'fetch_issues',
            repo_id: repoId
        });
    },

    async checkPRs(repoId) {
        return apiPost('/api/issues', {
            action: 'check_prs',
            repo_id: repoId
        });
    },

    async analyzeIssues(repoId, issueIds = []) {
        return apiPost('/api/analyze', {
            action: 'analyze_batch',
            repo_id: repoId,
            issue_ids: issueIds
        });
    },

    async createPR(issueId, context = '') {
        return apiPost('/api/launch', {
            issue_id: issueId,
            context: context
        });
    },

    async cancelPR(issueId) {
        return apiPost('/api/issues', {
            action: 'cancel_pr',
            issue_id: issueId
        });
    },

    async saveModelPreferences(assessmentModel, prCreationModel, codeReviewModel) {
        return settingsApi('save_model_preferences', {
            assessment_model: assessmentModel,
            pr_creation_model: prCreationModel,
            code_review_model: codeReviewModel
        });
    },

    async testConnection(model, modelType) {
        return settingsApi('test_connection', {
            model: model,
            model_type: modelType
        });
    },

    async saveRepo(id, localPath, defaultBranch, autoCreatePr, defaultMode) {
        return settingsApi('save_repo', {
            id: id,
            local_path: localPath,
            default_branch: defaultBranch,
            default_mode: defaultMode,
            auto_create_pr: autoCreatePr
        });
    },

    async deleteRepo(id) {
        return settingsApi('delete_repo', { id: id });
    },

    async fetchRepos(source) {
        return settingsApi('fetch_repos', { source: source });
    },

    async addRepo(source, sourceId, name) {
        return settingsApi('add_repo', {
            source: source,
            source_id: sourceId,
            name: name,
            local_path: '',
            default_branch: 'main',
            default_mode: 'plan',
            auto_create_pr: 0
        });
    }
};
