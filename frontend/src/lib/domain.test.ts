import { describe, expect, it } from 'vitest';
import { parseDomainQuery } from './domain';

describe('parseDomainQuery', () => {
  it.each([
    ['okafor', 'okafor', null],
    ['Okafor.NG', 'okafor', 'ng'],
    ['okafor.com.ng', 'okafor', 'com.ng'],
    ['  https://www.okafor-designs.com/about  ', 'okafor-designs', 'com'],
  ])('accepts %j', (input, label, tld) => {
    expect(parseDomainQuery(input)).toEqual({ ok: true, label, tld });
  });

  it.each([
    [''],
    ['-okafor'],
    ['okafor-'],
    ['oka for'],
    ['okafor.xyz'],
    ['a'.repeat(64)],
    ['xn--80ak6aa92e'],
    ['<script>'],
  ])('rejects %j with a message', (input) => {
    const result = parseDomainQuery(input);
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.error.length).toBeGreaterThan(0);
  });

  it('prefers the longest matching extension', () => {
    expect(parseDomainQuery('shop.com.ng')).toEqual({ ok: true, label: 'shop', tld: 'com.ng' });
  });
});
