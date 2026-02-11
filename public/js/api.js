// API functions for Scout - using common utilities

const API = {
    async getSettings() {
        return apiGet('/api/settings');
    },

    async fetchIssues(repoId) {
        return apiGet('/api/issues', { repo_id: repoId });
    },

    async checkForUpdates(repoId, lastTimestamp) {
        return apiGet('/api/issues', {
            repo_id: repoId,
            check_updates: 1,
            last_timestamp: lastTimestamp
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

    async analyzeIssues(repoId) {
        return apiPost('/api/analyze', {
            action: 'analyze_batch',
            repo_id: repoId
        });
    },

    async createPR(issueId, context = '') {
        return apiPost('/api/launch', {
            issue_id: issueId,
            context: context
        });
    },

    async saveModelPreferences(assessmentModel, prCreationModel) {
        return settingsApi('save_model_preferences', {
            assessment_model: assessmentModel,
            pr_creation_model: prCreationModel
        });
    },

    async saveRepo(id, localPath, defaultBranch, autoCreatePr) {
        return settingsApi('save_repo', {
            id: id,
            local_path: localPath,
            default_branch: defaultBranch,
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
            auto_create_pr: 0
        });
    }
};