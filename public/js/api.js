// API functions for Scout

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
            console.error('Failed to load settings:', error);
            throw error;
        }
    },

    async fetchIssues(repoId) {
        try {
            const response = await fetch(`/api/issues?repo_id=${repoId}`);
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to fetch issues');
        } catch (error) {
            console.error('Failed to fetch issues:', error);
            throw error;
        }
    },

    async syncIssues(repoId) {
        try {
            const response = await fetch('/api/issues', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'fetch_issues',
                    repo_id: repoId
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to sync issues');
        } catch (error) {
            console.error('Failed to sync issues:', error);
            throw error;
        }
    },

    async analyzeIssues(repoId) {
        try {
            const response = await fetch('/api/analyze', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'analyze_batch',
                    repo_id: repoId
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to analyze issues');
        } catch (error) {
            console.error('Failed to analyze issues:', error);
            throw error;
        }
    },

    async createPR(issueId) {
        try {
            const response = await fetch('/api/launch', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'create_pr',
                    issue_id: issueId
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to create PR');
        } catch (error) {
            console.error('Failed to create PR:', error);
            throw error;
        }
    },

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
            console.error('Failed to save model preferences:', error);
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
            console.error('Failed to save repository:', error);
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
            console.error('Failed to delete repository:', error);
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
            console.error('Failed to fetch repositories:', error);
            throw error;
        }
    },

    async addRepo(source, sourceId, name) {
        try {
            const response = await fetch('/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add_repo',
                    source: source,
                    source_id: sourceId,
                    name: name,
                    local_path: '',
                    default_branch: 'main',
                    auto_create_pr: 0
                })
            });
            const data = await response.json();
            if (data.status === 'ok') {
                return data;
            }
            throw new Error(data.error || 'Failed to add repository');
        } catch (error) {
            console.error('Failed to add repository:', error);
            throw error;
        }
    }
};