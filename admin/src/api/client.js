/**
 * API Client for AI Forge Dev Tools
 *
 * @package AIForgeDevTools
 */

const getConfig = () => {
    return window.aiforgeDevData || {
        apiUrl: '/wp-json/aiforge-dev/v1',
        nonce: '',
    };
};

const request = async (endpoint, options = {}) => {
    const config = getConfig();
    const url = `${config.apiUrl}${endpoint}`;

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
};

export default api;
