const { useEffect, useState } = wp.element;
const { Button, TabPanel, Spinner } = wp.components;
import { api } from '../lib/api';

const COPY = {
  nginx: 'Paste into your server block, above the PHP location, then reload nginx.',
  apache: 'Paste into .htaccess above the "# BEGIN WordPress" block.',
  cloudflare: 'Deploy as a Worker on a route matching your site.',
};

export default function ServerRulesPanel() {
  const [rules, setRules] = useState(null);
  const [copied, setCopied] = useState(null);

  useEffect(() => {
    api.serverRules().then(setRules);
  }, []);

  if (!rules) {
    return (
      <div className="rcr-loading">
        <Spinner />
        <span>Generating rules for this install…</span>
      </div>
    );
  }

  const copy = (key) => {
    navigator.clipboard.writeText(rules[key]);
    setCopied(key);
    setTimeout(() => setCopied(null), 2000);
  };

  return (
    <>
      <p className="rcr-note">
        A cache hit served through PHP still costs a process and 15–40ms. These
        rules let the web server answer from the same files with PHP never
        starting. Turn on <strong>Write server-readable copies</strong> first, or
        there will be nothing for them to find.
      </p>

      <TabPanel
        className="rcr-rules-tabs"
        tabs={[
          { name: 'nginx', title: 'nginx' },
          { name: 'apache', title: 'Apache' },
          { name: 'cloudflare', title: 'Cloudflare Worker' },
        ]}
      >
        {(tab) => (
          <div className="rcr-rules">
            <p className="rcr-note">{COPY[tab.name]}</p>
            <pre className="rcr-code">{rules[tab.name]}</pre>
            <Button variant="secondary" onClick={() => copy(tab.name)}>
              {copied === tab.name ? 'Copied' : 'Copy rules'}
            </Button>
          </div>
        )}
      </TabPanel>
    </>
  );
}
