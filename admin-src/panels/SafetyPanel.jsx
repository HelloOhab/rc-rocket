const { useEffect, useState } = wp.element;
const { Button, Notice, PanelBody, RangeControl, Spinner, ToggleControl } = wp.components;
import { api } from '../lib/api';

const when = (ts) => new Date(ts * 1000).toLocaleString();

export default function SafetyPanel({ settings, update, reload }) {
  const [history, setHistory] = useState(null);
  const [errors, setErrors] = useState(null);
  const [busy, setBusy] = useState(false);
  const safety = settings.safety || {};
  const general = settings.general || {};

  const load = () => {
    api.getHistory().then(setHistory);
    api.getErrors().then((r) => setErrors(r.errors));
  };

  useEffect(load, []);

  const restore = async (id) => {
    setBusy(true);
    await api.restoreHistory(id);
    setBusy(false);
    load();
    reload();
  };

  const clearErrors = async (baseline = false) => {
    setBusy(true);
    await api.clearErrors(baseline);
    setBusy(false);
    load();
    reload();
  };

  return (
    <>
      {history && history.safe_mode.active && (
        <Notice status="warning" isDismissible={false}>
          Safe mode is active ({history.safe_mode.reason}). RC Rocket is not
          changing any page output right now.
          {history.safe_mode.auto_reason && <> Reason given: {history.safe_mode.auto_reason}.</>}
          {history.safe_mode.reason === 'auto' && (
            <>
              {' '}
              <Button variant="link" onClick={() => clearErrors(false)}>
                Resume optimization now
              </Button>
            </>
          )}
        </Notice>
      )}

      <PanelBody title="Kill switch" initialOpen>
        <ToggleControl
          label="Safe mode"
          help="Turns off every optimization without deactivating the plugin. Settings are kept."
          checked={!!general.safe_mode}
          onChange={(v) => update('general', 'safe_mode', v)}
        />
        <ToggleControl
          label="Skip optimization for logged-in users"
          help="Keeps the admin experience identical to an unoptimized site, which makes debugging far easier."
          checked={!!general.skip_logged_in}
          onChange={(v) => update('general', 'skip_logged_in', v)}
        />
        <p className="rcr-note">
          Two ways in that do not need this screen: add{' '}
          <code>?rcr_safe=1</code> to any URL to bypass optimization for one
          request, or put{' '}
          <code>define( 'RC_ROCKET_SAFE_MODE', true );</code> in wp-config.php if
          you are ever locked out.
        </p>
      </PanelBody>

      <PanelBody title="Automatic rollback" initialOpen>
        <ToggleControl
          label="Watch for JavaScript errors from real visitors"
          checked={!!safety.error_beacon}
          onChange={(v) => update('safety', 'error_beacon', v)}
          help="A small script reports uncaught errors and failed resources. No personal data, no third party."
        />
        <ToggleControl
          label="Switch everything off when errors spike"
          checked={!!safety.auto_safe_mode}
          onChange={(v) => update('safety', 'auto_safe_mode', v)}
          help="Safe mode engages for two hours and you get an email. Better to lose the optimization than the site."
        />
        <RangeControl
          label="Errors within 15 minutes before switching off"
          value={safety.error_threshold || 5}
          min={2}
          max={30}
          onChange={(v) => update('safety', 'error_threshold', v)}
        />
        <ToggleControl
          label="Email me when it happens"
          checked={!!safety.notify_admin}
          onChange={(v) => update('safety', 'notify_admin', v)}
        />
      </PanelBody>

      <PanelBody title="Reported errors" initialOpen>
        {errors === null ? (
          <Spinner />
        ) : errors.length === 0 ? (
          <p className="rcr-note">Nothing reported. That is the result you want.</p>
        ) : (
          <>
            <table className="rcr-table">
              <tbody>
                {errors.slice(0, 20).map((e, i) => (
                  <tr key={i}>
                    <th>
                      {when(e.time)}
                      {e.count > 1 && <span className="rcr-count">×{e.count}</span>}
                    </th>
                    <td>
                      <strong>{e.kind}</strong>
                      {e.own_site && <span className="rcr-flag rcr-flag--own">your site</span>}
                      {!e.own_site && e.source && <span className="rcr-flag">third party</span>}
                      {e.baseline && <span className="rcr-flag rcr-flag--base">already happening</span>}
                      {!e.baseline && !e.counts && (
                        <span className="rcr-flag">cannot trigger rollback</span>
                      )}
                      <br />
                      {e.message}
                      {e.source && (
                        <>
                          <br />
                          <code className="rcr-source">{e.source}</code>
                        </>
                      )}
                      {e.pages && e.pages.length > 0 && (
                        <>
                          <br />
                          <span className="rcr-note">on {e.pages.join(', ')}</span>
                        </>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <p className="rcr-note">
              Errors flagged <strong>your site</strong> are worth fixing at the
              source — a missing image size, a broken template, a file that no
              longer exists. Only script failures can ever trigger a rollback:
              a 404ing image cannot be caused by deferring a script, so undoing
              the optimization would not fix it.
            </p>
            <div className="rcr-actions">
              <Button variant="primary" isBusy={busy} onClick={() => clearErrors(false)}>
                Clear the log and leave safe mode
              </Button>
              <Button variant="secondary" isBusy={busy} onClick={() => clearErrors(true)}>
                Also forget the baseline
              </Button>
            </div>
            <p className="rcr-note">
              Errors marked <strong>already happening</strong> were recorded
              while nothing was being optimized, so they are part of how the
              site normally behaves and never trigger a rollback. Only new,
              distinct errors count — and only while defer, delay, lazy render
              or an asset rule is actually switched on.
            </p>
          </>
        )}
      </PanelBody>

      <PanelBody title="Change history" initialOpen>
        {history === null ? (
          <Spinner />
        ) : history.entries.length === 0 ? (
          <p className="rcr-note">No changes recorded yet.</p>
        ) : (
          history.entries.map((entry) => (
            <div key={entry.id} className="rcr-history">
              <div>
                <strong>{when(entry.time)}</strong> · {entry.user}
                <ul className="rcr-diff">
                  {entry.changes.map((c, i) => (
                    <li key={i}>
                      <code>{c.path}</code> {c.from} → {c.to}
                    </li>
                  ))}
                </ul>
              </div>
              <Button variant="secondary" isBusy={busy} onClick={() => restore(entry.id)}>
                Roll back to before this
              </Button>
            </div>
          ))
        )}
      </PanelBody>
    </>
  );
}
