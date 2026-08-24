const { useEffect, useState } = wp.element;
const { Button, Notice, Spinner } = wp.components;
import { api } from '../lib/api';

const LABEL = { pass: 'Pass', warn: 'Check', fail: 'Fail', info: 'Info' };

export default function CheckPanel() {
  const [result, setResult] = useState(null);
  const [busy, setBusy] = useState(false);

  const run = () => {
    setBusy(true);
    api.selfTest().then((r) => {
      setResult(r);
      setBusy(false);
    });
  };

  useEffect(run, []);

  if (!result) {
    return (
      <div className="rcr-loading">
        <Spinner />
        <span>Loading your home page the way a visitor would…</span>
      </div>
    );
  }

  const { checks, summary, fetched } = result;

  return (
    <>
      <div className="rcr-actions">
        <Button variant="primary" isBusy={busy} onClick={run}>
          Run the checks again
        </Button>
        <span className="rcr-note">
          {summary.pass} passed · {summary.warn} to look at · {summary.fail} failed
        </span>
      </div>

      {!fetched && (
        <Notice status="error" isDismissible={false}>
          The home page could not be fetched over HTTP, so the checks that read
          real markup were skipped. Loopback requests may be blocked.
        </Notice>
      )}

      <div className="rcr-checks">
        {checks.map((c, i) => (
          <div key={i} className={`rcr-check is-${c.status}`}>
            <span className="rcr-check__badge">{LABEL[c.status]}</span>
            <div>
              <strong>{c.label}</strong>
              <div className="rcr-check__detail">{c.detail}</div>
              {c.fix && <div className="rcr-check__fix">{c.fix}</div>}
            </div>
          </div>
        ))}
      </div>

      <p className="rcr-note">
        These read what your visitors actually received, not what the settings
        say should happen. If a change is not showing up here, it is not
        reaching anyone — clear the cache and run them again.
      </p>
    </>
  );
}
