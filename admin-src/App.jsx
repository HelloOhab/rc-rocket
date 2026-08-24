const { useCallback, useEffect, useState } = wp.element;
const { Button, TabPanel, Notice } = wp.components;
import { api } from './lib/api';
import Dashboard from './panels/Dashboard';
import CacheSettings from './panels/CacheSettings';
import ServerRulesPanel from './panels/ServerRulesPanel';
import AssetsPanel from './panels/AssetsPanel';
import JsPanel from './panels/JsPanel';
import MediaPanel from './panels/MediaPanel';
import SafetyPanel from './panels/SafetyPanel';
import CheckPanel from './panels/CheckPanel';

export default function App() {
  const [settings, setSettings] = useState(null);
  const [status, setStatus] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState(null);

  const loadStatus = useCallback(() => {
    api.getStatus().then(setStatus).catch((e) => setError(e.message));
  }, []);

  useEffect(() => {
    api
      .getSettings()
      .then((r) => setSettings(r.settings))
      .catch((e) => setError(e.message));
    loadStatus();
  }, [loadStatus]);

  const update = (namespace, key, value) => {
    setSettings((prev) => ({
      ...prev,
      [namespace]: { ...prev[namespace], [key]: value },
    }));
    setDirty(true);
  };

  const save = async () => {
    setBusy(true);
    try {
      const result = await api.saveSettings(settings);
      setSettings(result.settings);
      setDirty(false);
      loadStatus();
    } catch (e) {
      setError(e.message);
    }
    setBusy(false);
  };

  const purge = async (scope) => {
    setBusy(true);
    const result = await api.purge(scope);
    setBusy(false);
    return result;
  };

  const preload = async (action) => {
    setBusy(true);
    const result = await api.preload(action);
    setBusy(false);
    return result;
  };

  const hostOwnsCache = status && status.hosting && status.hosting.page_cache;
  const canEditServer = !status || !status.hosting || status.hosting.editable_server_config;

  const tabs = [
    { name: 'dashboard', title: 'Overview' },
    { name: 'assets', title: 'Assets' },
    { name: 'media', title: 'Media' },
    { name: 'js', title: 'JavaScript' },
    { name: 'cache', title: hostOwnsCache ? 'Cache & Divi' : 'Cache' },
    { name: 'safety', title: 'Safety' },
    { name: 'check', title: 'System check' },
  ];

  if (canEditServer) {
    tabs.push({ name: 'server', title: 'Server rules' });
  }

  return (
    <div className="rcr-app">
      <header className="rcr-header">
        <div>
          <h1 className="rcr-title">RC Rocket</h1>
          <p className="rcr-subtitle">Performance tuning built around Divi</p>
        </div>
        <div className="rcr-header__actions">
          {dirty && <span className="rcr-dirty">Unsaved changes</span>}
          <Button variant="primary" isBusy={busy} disabled={!dirty || busy} onClick={save}>
            Save changes
          </Button>
        </div>
      </header>

      {error && (
        <Notice status="error" onRemove={() => setError(null)}>
          {error}
        </Notice>
      )}

      <TabPanel
        className="rcr-tabs"
        tabs={tabs}
      >
        {(tab) => (
          <div className="rcr-panel">
            {tab.name === 'dashboard' && (
              <Dashboard
                status={status}
                busy={busy}
                onPurge={purge}
                onPreload={preload}
                reload={loadStatus}
              />
            )}
            {tab.name === 'assets' && settings && (
              <AssetsPanel settings={settings} update={update} status={status} />
            )}
            {tab.name === 'media' && settings && (
              <MediaPanel settings={settings} update={update} status={status} />
            )}
            {tab.name === 'js' && settings && (
              <JsPanel settings={settings} update={update} status={status} />
            )}
            {tab.name === 'cache' && settings && (
              <CacheSettings settings={settings} update={update} />
            )}
            {tab.name === 'safety' && settings && (
              <SafetyPanel settings={settings} update={update} reload={loadStatus} />
            )}
            {tab.name === 'check' && <CheckPanel />}
            {tab.name === 'server' && <ServerRulesPanel />}
          </div>
        )}
      </TabPanel>
    </div>
  );
}
