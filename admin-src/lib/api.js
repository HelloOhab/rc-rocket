/**
 * Thin wrapper over the plugin's REST namespace.
 * Every screen in the app reads and writes through here, so WP-CLI, CI and the
 * UI can never drift apart.
 */
const boot = window.RCRocketBoot || {};

// wp-api-fetch is already configured with this site's REST root and a nonce
// by WordPress itself; re-registering middleware here would double them up.
const { apiFetch } = wp;

const call = (path, options = {}) =>
  apiFetch({ path: `/rc-rocket/v1${path}`, ...options });

export const api = {
  boot,
  getSettings: () => call('/settings'),
  saveSettings: (settings) => call('/settings', { method: 'POST', data: settings }),
  getStatus: () => call('/status'),
  purge: (scope = 'all', value = '') =>
    call('/purge', { method: 'POST', data: { scope, value } }),
  preload: (action = 'start') =>
    call('/preload', { method: 'POST', data: { action } }),
  serverRules: () => call('/server-rules'),
  exportConfig: () => call('/config'),
  selfTest: () => call('/self-test'),
  getAssets: () => call('/assets'),
  clearAssets: () => call('/assets', { method: 'DELETE' }),
  getHistory: () => call('/history'),
  restoreHistory: (id) => call('/history/restore', { method: 'POST', data: { id } }),
  getErrors: () => call('/errors'),
  clearErrors: (baseline = false) =>
    call(`/errors${baseline ? '?baseline=1' : ''}`, { method: 'DELETE' }),
};

export const formatBytes = (bytes) => {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
};
