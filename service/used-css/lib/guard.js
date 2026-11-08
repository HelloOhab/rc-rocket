/**
 * Requests this service accepts: a valid key, and a public web page.
 */
import { lookup } from 'node:dns/promises';
import { timingSafeEqual } from 'node:crypto';
import net from 'node:net';

/** Keys from RCR_CSS_KEYS (comma separated), so one can be rotated at a time. */
export function authorised(header, keys = process.env.RCR_CSS_KEYS || '') {
  const given = String(header || '').replace(/^Bearer\s+/i, '').trim();
  if (!given) return false;
  return keys.split(',').map((k) => k.trim()).filter(Boolean).some((key) => {
    const a = Buffer.from(given);
    const b = Buffer.from(key);
    return a.length === b.length && timingSafeEqual(a, b);
  });
}

/**
 * The address must be http(s) on the standard ports, and its host must not
 * resolve to a private, loopback or link-local address: this service must
 * not become a way into anyone's internal network.
 */
export async function publicUrl(value) {
  let url;
  try {
    url = new URL(String(value));
  } catch (e) {
    return { ok: false, reason: 'not a URL' };
  }
  if (!/^https?:$/.test(url.protocol)) return { ok: false, reason: 'only http and https' };
  if (url.port && !['80', '443'].includes(url.port)) return { ok: false, reason: 'only the standard ports' };
  if (url.username || url.password) return { ok: false, reason: 'no credentials in the URL' };

  let addresses;
  try {
    addresses = net.isIP(url.hostname) ? [{ address: url.hostname }] : await lookup(url.hostname, { all: true });
  } catch (e) {
    return { ok: false, reason: 'the host does not resolve' };
  }
  if (addresses.length === 0 || addresses.some((a) => isPrivate(a.address))) {
    return { ok: false, reason: 'the host is not a public address' };
  }
  return { ok: true, url };
}

export function isPrivate(ip) {
  if (net.isIPv4(ip)) {
    const [a, b] = ip.split('.').map(Number);
    return a === 10 || a === 127 || a === 0 || (a === 169 && b === 254) || (a === 172 && b >= 16 && b <= 31)
      || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || a >= 224;
  }
  const v6 = ip.toLowerCase();
  if (v6.startsWith('::ffff:')) return isPrivate(v6.slice(7));
  return v6 === '::1' || v6 === '::' || v6.startsWith('fc') || v6.startsWith('fd') || v6.startsWith('fe8') || v6.startsWith('fe9') || v6.startsWith('fea') || v6.startsWith('feb');
}
