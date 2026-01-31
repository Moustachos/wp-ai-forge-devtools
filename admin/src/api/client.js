/**
 * API Client for AI Forge Dev Tools
 *
 * @package AIForgeDevTools
 */

const getConfig = () => {
    return window.aiforgeDevData || {
        apiUrl: '/wp-json/aiforge-dev/v1',
        mainApiUrl: '/wp-json/aiforge/v1',
        nonce: '',
    };
};

const request = async (endpoint, options = {}, useMainApi = false) => {
    const config = getConfig();
    const baseUrl = useMainApi ? config.mainApiUrl : config.apiUrl;
    const url = `${baseUrl}${endpoint}`;

    const headers = {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
        ...options.headers,
    };

    const response = await fetch(url, {
        ...options,
        headers,
    });

    const data = await response.json();

    if (!response.ok || data.success === false) {
        const error = new Error(data.error?.message || 'Une erreur est survenue');
        error.response = { data };
        throw error;
    }

    return data;
};

export const api = {
    getDemoMode: () => request('/demo-mode'),

    setDemoMode: (enabled) =>
        request('/demo-mode', {
            method: 'POST',
            body: JSON.stringify({ enabled }),
        }),

    getDevMode: () => request('/dev-mode'),

    setDevMode: (enabled) =>
        request('/dev-mode', {
            method: 'POST',
            body: JSON.stringify({ enabled }),
        }),

    testSanitizer: (markdown, content, template = '') =>
        request('/sanitizer/test', {
            method: 'POST',
            body: JSON.stringify({ markdown, content, template }),
        }),

    // Main plugin API (templates)
    getTemplates: () => request('/ci-templates', {}, true),
    getTemplate: (id) => request(`/ci-templates/${id}`, {}, true),
};

export default api;
