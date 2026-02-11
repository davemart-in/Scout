// Utility functions for Scout

// Template system
const templates = {};

function loadTemplate(name) {
    const element = document.getElementById(`tmpl-${name}`);
    if (!element) {
        throw new Error(`Template "tmpl-${name}" not found in DOM`);
    }
    return element.innerHTML;
}

function renderTemplate(template, data = {}) {
    return template.replace(/\{\{(\w+)\}\}/g, (match, key) => {
        return data.hasOwnProperty(key) ? data[key] : match;
    });
}

function _tmpl(name, data = {}) {
    if (!templates[name]) {
        templates[name] = loadTemplate(name);
    }
    return renderTemplate(templates[name], data);
}

// Toast management
const toastManager = {
    container: null,
    toasts: [],

    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    },

    show(message, type = 'info', duration = 5000) {
        this.init();

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = message;

        // Add to container
        this.container.appendChild(toast);
        this.toasts.push(toast);

        // Update positions for stacking
        this.updatePositions();

        // Trigger animation
        setTimeout(() => toast.classList.add('show'), 10);

        // Auto-dismiss after duration
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                const index = this.toasts.indexOf(toast);
                if (index > -1) {
                    this.toasts.splice(index, 1);
                }
                toast.remove();
                this.updatePositions();
            }, 300);
        }, duration);
    },

    updatePositions() {
        let offset = 0;
        this.toasts.forEach(toast => {
            toast.style.transform = `translateY(${offset}px)`;
            offset += toast.offsetHeight + 10;
        });
    }
};

// Legacy function wrapper for compatibility
function showToast(message, type = 'info') {
    toastManager.show(message, type);
}

// Calculate relative time
function relativeTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);

    const intervals = [
        { label: 'year', seconds: 31536000 },
        { label: 'month', seconds: 2592000 },
        { label: 'day', seconds: 86400 },
        { label: 'hour', seconds: 3600 },
        { label: 'minute', seconds: 60 }
    ];

    for (const interval of intervals) {
        const count = Math.floor(seconds / interval.seconds);
        if (count >= 1) {
            return count === 1
                ? `${count} ${interval.label} ago`
                : `${count} ${interval.label}s ago`;
        }
    }

    return 'just now';
}

// Format date for tooltip
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

// Create badge HTML
function createBadge(text, type = 'default') {
    return `<span class="badge badge-${type}">${text}</span>`;
}

// Create assessment badge
function createAssessmentBadge(assessment) {
    switch (assessment) {
        case 'pending':
            return createBadge('Pending', 'gray');
        case 'too_complex':
            return createBadge('Too Complex', 'red');
        case 'agentic_pr_capable':
            return createBadge('PR Capable', 'green');
        default:
            return createBadge('Unknown', 'gray');
    }
}

// Create PR status content
function createActionContent(issue) {
    const prStatus = issue.pr_status || 'none';
    const assessment = issue.assessment || 'pending';

    switch (prStatus) {
        case 'none':
            if (assessment === 'agentic_pr_capable') {
                return `<button class="btn btn-primary btn-small create-pr" data-issue-id="${issue.id}">Create PR</button>`;
            } else if (assessment === 'too_complex') {
                return '—';
            } else {
                return `<button class="btn btn-primary btn-small create-pr" data-issue-id="${issue.id}" disabled>Create PR</button>`;
            }
        case 'in_progress':
            return '<span class="status-badge badge-blue">In Progress...</span>';
        case 'branch_pushed':
            return issue.pr_branch
                ? `<span class="status-badge badge-blue">Branch Pushed</span>`
                : '<span class="status-badge badge-blue">Branch Pushed</span>';
        case 'pr_created':
            return issue.pr_url
                ? `<a href="${issue.pr_url}" target="_blank" class="status-badge badge-purple">PR Created</a>`
                : '<span class="status-badge badge-purple">PR Created</span>';
        case 'needs_review':
            return issue.pr_url
                ? `<a href="${issue.pr_url}" target="_blank" class="status-badge badge-yellow">Needs Review</a>`
                : '<span class="status-badge badge-yellow">Needs Review</span>';
        case 'failed':
            return `<span class="status-badge badge-red">Failed</span> <button class="btn btn-small retry-pr" data-issue-id="${issue.id}">Retry</button>`;
        default:
            return '—';
    }
}

// Format labels
function formatLabels(labels) {
    if (!labels || !Array.isArray(labels) || labels.length === 0) {
        return '';
    }
    return labels.map(label => createBadge(label, 'label')).join(' ');
}

// Format priority
function formatPriority(priority) {
    if (!priority) return '—';

    const priorityMap = {
        'Urgent': 'red',
        'High': 'orange',
        'Medium': 'yellow',
        'Low': 'gray',
        'None': 'gray'
    };

    const type = priorityMap[priority] || 'gray';
    return createBadge(priority, type);
}