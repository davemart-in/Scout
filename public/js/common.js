// Common utilities for Scout JS files

/**
 * Base API request handler with error handling
 */
async function apiRequest(url, options = {}) {
    try {
        const response = await fetch(url, options);
        const data = await response.json();

        if (data.status === 'ok' || data.status === 'launched') {
            return data;
        }

        throw new Error(data.error || 'API request failed');
    } catch (error) {
        console.error(`API request failed: ${url}`, error);
        throw error;
    }
}

/**
 * Make a GET request
 */
async function apiGet(endpoint, params = {}) {
    const url = new URL(endpoint, window.location.origin);
    Object.keys(params).forEach(key => {
        if (params[key] !== undefined && params[key] !== null) {
            url.searchParams.append(key, params[key]);
        }
    });
    return apiRequest(url.toString());
}

/**
 * Make a POST request
 */
async function apiPost(endpoint, data = {}) {
    return apiRequest(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
}

/**
 * Common settings API endpoint helper
 */
async function settingsApi(action, additionalData = {}) {
    return apiPost('/api/settings', {
        action: action,
        ...additionalData
    });
}

/**
 * Debounce function for rate limiting
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Format timestamp to relative time
 */
function timeAgo(timestamp) {
    if (!timestamp) return 'never';

    const seconds = Math.floor((new Date() - new Date(timestamp)) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)} days ago`;

    return new Date(timestamp).toLocaleDateString();
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Parse and format labels
 */
function formatLabels(labels) {
    if (!labels) return '';

    const labelArray = Array.isArray(labels) ? labels :
                      (typeof labels === 'string' ? JSON.parse(labels) : []);

    return labelArray.map(label =>
        `<span class="label">${escapeHtml(label)}</span>`
    ).join('');
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status, className = '') {
    const statusClasses = {
        'pending': 'badge-secondary',
        'agentic_pr_capable': 'badge-success',
        'too_complex': 'badge-warning',
        'in_progress': 'badge-info pulsing',
        'pr_created': 'badge-success',
        'branch_pushed': 'badge-info',
        'needs_review': 'badge-warning',
        'failed': 'badge-danger'
    };

    const statusLabels = {
        'pending': 'Pending',
        'agentic_pr_capable': 'PR Capable',
        'too_complex': 'Too Complex',
        'in_progress': 'In Progress...',
        'pr_created': 'PR Created',
        'branch_pushed': 'Branch Pushed',
        'needs_review': 'Needs Review',
        'failed': 'Failed'
    };

    const badgeClass = statusClasses[status] || 'badge-secondary';
    const label = statusLabels[status] || status;

    return `<span class="badge ${badgeClass} ${className}">${label}</span>`;
}

/**
 * Get priority badge HTML
 */
function getPriorityBadge(priority) {
    const priorityClasses = {
        'urgent': 'priority-urgent',
        'high': 'priority-high',
        'medium': 'priority-medium',
        'low': 'priority-low'
    };

    const priorityClass = priorityClasses[priority?.toLowerCase()] || 'priority-medium';
    const priorityLabel = priority || 'Medium';

    return `<span class="priority ${priorityClass}">${priorityLabel}</span>`;
}

/**
 * Create and show a toast notification
 */
function showToast(message, type = 'info', duration = 5000) {
    // Check if toast container exists, create if not
    let toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toastContainer';
        toastContainer.className = 'toast-container';
        document.body.appendChild(toastContainer);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;

    // Position new toast above existing ones
    const existingToasts = toastContainer.querySelectorAll('.toast.show');
    const offset = existingToasts.length * 60; // 60px per toast
    toast.style.bottom = `${20 + offset}px`;

    toastContainer.appendChild(toast);

    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);

    // Remove after duration
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => {
            toast.remove();
            // Reposition remaining toasts
            const remaining = toastContainer.querySelectorAll('.toast.show');
            remaining.forEach((t, i) => {
                t.style.bottom = `${20 + (i * 60)}px`;
            });
        }, 300);
    }, duration);

    return toast;
}

/**
 * Local storage wrapper with JSON support
 */
const storage = {
    get(key, defaultValue = null) {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : defaultValue;
        } catch (e) {
            console.error('Storage get error:', e);
            return defaultValue;
        }
    },

    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Storage set error:', e);
            return false;
        }
    },

    remove(key) {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Storage remove error:', e);
            return false;
        }
    }
};

/**
 * Simple event emitter for component communication
 */
class EventBus {
    constructor() {
        this.events = {};
    }

    on(event, callback) {
        if (!this.events[event]) {
            this.events[event] = [];
        }
        this.events[event].push(callback);
    }

    off(event, callback) {
        if (this.events[event]) {
            this.events[event] = this.events[event].filter(cb => cb !== callback);
        }
    }

    emit(event, data) {
        if (this.events[event]) {
            this.events[event].forEach(callback => callback(data));
        }
    }
}

// Global event bus instance
const eventBus = new EventBus();

// ============================================================================
// Template System
// ============================================================================

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
        // Don't escape HTML - the data should already be safe or intentionally contain HTML
        return data.hasOwnProperty(key) ? data[key] : match;
    });
}

function _tmpl(name, data = {}) {
    if (!templates[name]) {
        templates[name] = loadTemplate(name);
    }
    return renderTemplate(templates[name], data);
}

// ============================================================================
// Additional Utilities
// ============================================================================

// Alias for compatibility with existing code
const relativeTime = timeAgo;

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

// Create assessment badge - uses common getStatusBadge
function createAssessmentBadge(assessment) {
    return getStatusBadge(assessment);
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

// Format priority - use common getPriorityBadge
function formatPriority(priority) {
    return priority ? getPriorityBadge(priority) : '—';
}