export const LAUNCH_TLDS = ['com', 'ng', 'com.ng', 'net', 'org', 'co'] as const;
export type Tld = (typeof LAUNCH_TLDS)[number];

export type DomainQuery =
  | { ok: true; label: string; tld: Tld | null }
  | { ok: false; error: string };

const LABEL = /^(?!-)[a-z0-9-]{1,63}(?<!-)$/;

/**
 * Normalises and validates what a customer typed into domain search.
 * Accepts "example", "example.ng" or "https://www.example.com.ng/path".
 * Internationalised (punycode) names are out of scope for v1.
 */
export function parseDomainQuery(input: string): DomainQuery {
  let value = input.trim().toLowerCase();
  if (value === '') return { ok: false, error: 'Enter a domain name to search.' };
  value = value.replace(/^[a-z]+:\/\//, '').replace(/^www\./, '').split(/[/?#]/)[0] ?? '';

  let tld: Tld | null = null;
  for (const candidate of [...LAUNCH_TLDS].sort((a, b) => b.length - a.length)) {
    if (value.endsWith(`.${candidate}`)) {
      tld = candidate;
      value = value.slice(0, -(candidate.length + 1));
      break;
    }
  }

  if (value.includes('.')) {
    return { ok: false, error: `We don't sell that extension yet. Try .${LAUNCH_TLDS.join(', .')}.` };
  }
  if (/^[a-z0-9]{2}--/.test(value)) {
    return { ok: false, error: 'Internationalised domain names are not supported yet.' };
  }
  if (!LABEL.test(value)) {
    return {
      ok: false,
      error: 'Use 1–63 letters, numbers or hyphens. A name cannot start or end with a hyphen.',
    };
  }
  return { ok: true, label: value, tld };
}
