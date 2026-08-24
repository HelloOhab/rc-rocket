const { useState } = wp.element;
const { Button, Notice, Spinner } = wp.components;
import { formatBytes } from '../lib/api';

/**
 * The status strip is the signature of this UI: a single row of live counters
 * that tells you the three things you actually came here to check — is the
 * cache on, how much of the site is warm, and does Divi agree with us.
 */
export default function Dashboard({ status, busy, onPurge, onPreload, reload }) {
  const [message, setMessage] = useState(null);

  if (!status) {
    return (
      <div className="rcr-loading">
        <Spinner />
        <span>Reading cache state…</span>
      </div>
    );
  }

  const { cache, dropin, divi, preload, server, hosting } = status;
  const hostOwnsCache = hosting && hosting.page_cache;
  const running = preload && preload.running;

  const act = async (fn, text) => {
    const result = await fn();
    setMessage(text(result));
    reload();
  };

  return (
    <>
      {message && (
        <Notice status="success" onRemove={() => setMessage(null)}>
          {message}
        </Notice>
      )}

      {hostOwnsCache && (
        <Notice status="info" isDismissible={false}>
          <strong>{hosting.label} detected.</strong> {hosting.reason} Everything
          else in RC Rocket still applies, and it is where your remaining speed
          is.
        </Notice>
      )}

      {!hostOwnsCache && dropin.foreign_dropin && (
        <Notice status="error" isDismissible={false}>
          Another plugin owns <code>advanced-cache.php</code>. Deactivate it, then
          reactivate RC Rocket. Two page caches on one site fight each other.
        </Notice>
      )}

      {!hostOwnsCache && !dropin.wp_cache && (
        <Notice status="warning" isDismissible={false}>
          <code>WP_CACHE</code> is off, so cached pages still boot WordPress. Add{' '}
          <code>define( 'WP_CACHE', true );</code> to the top of{' '}
          <code>wp-config.php</code>.
        </Notice>
      )}

      <div className="rcr-strip">
        <div className="rcr-metric">
          <span className="rcr-metric__value">
            {hostOwnsCache ? hosting.label : cache.files}
          </span>
          <span className="rcr-metric__label">
            {hostOwnsCache ? 'page cache owner' : 'pages cached'}
          </span>
        </div>
        <div className="rcr-metric">
          <span className="rcr-metric__value">
            {hostOwnsCache ? 'forwarded' : formatBytes(cache.bytes)}
          </span>
          <span className="rcr-metric__label">
            {hostOwnsCache ? 'purges' : 'on disk'}
          </span>
        </div>
        <div className="rcr-metric">
          <span className={`rcr-metric__value ${hostOwnsCache || dropin.installed ? 'is-on' : 'is-off'}`}>
            {hostOwnsCache ? 'passthrough' : dropin.installed ? 'active' : 'inactive'}
          </span>
          <span className="rcr-metric__label">mode</span>
        </div>
        <div className="rcr-metric">
          <span className="rcr-metric__value">{divi.active ? divi.version : '—'}</span>
          <span className="rcr-metric__label">
            {divi.active ? divi.engine : 'Divi not detected'}
          </span>
        </div>
      </div>

      <div className="rcr-actions">
        <Button variant="primary" disabled={busy} onClick={() => act(() => onPurge('all'), () => (hostOwnsCache ? `Purge sent to ${hosting.label}.` : 'Cache cleared.'))}>
          {hostOwnsCache ? `Clear ${hosting.label}'s cache` : 'Clear all cached pages'}
        </Button>
        {!hostOwnsCache && (
          <Button variant="secondary" disabled={busy} onClick={() => act(() => onPurge('expired'), (r) => `Cleared ${r.entries} expired pages.`)}>
            Clear expired only
          </Button>
        )}
        {!hostOwnsCache && <Button
          variant={running ? 'secondary' : 'primary'}
          disabled={busy}
          onClick={() =>
            act(
              () => onPreload(running ? 'stop' : 'start'),
              (r) => (running ? 'Preload stopped.' : `Queued ${r.queued} URLs.`)
            )
          }
        >
          {running ? 'Stop warming' : 'Warm the cache'}
        </Button>}
      </div>

      {running && (
        <p className="rcr-progress">
          Warming {preload.done} of {preload.total} URLs. Batches run on cron, a
          few at a time, so your own server is not the one taking the load.
        </p>
      )}

      <table className="rcr-table">
        <tbody>
          <tr>
            <th>Server</th>
            <td>{server.software || 'unknown'} · PHP {server.php}</td>
          </tr>
          <tr>
            <th>Divi asset cache</th>
            <td>
              <code>{divi.et_cache_dir}</code>{' '}
              {divi.et_cache_writable ? '' : '— not writable'}
            </td>
          </tr>
          <tr>
            <th>Theme Builder</th>
            <td>
              {divi.theme_builder
                ? 'Detected. Cached pages are tagged with their template, so editing a global header clears only the pages that use it.'
                : 'Not in use.'}
            </td>
          </tr>
        </tbody>
      </table>
    </>
  );
}
