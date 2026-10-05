// Sample data for the design prototype only. Prices are illustrative, not the
// approved price list; nothing here is fetched from or sent to any server.
import { money, type Money } from '../lib/money';
import type { Tld } from '../lib/domain';
import type { Tone } from '../components';

export const sampleCustomer = { name: 'Adaeze Okafor', email: 'adaeze@example.com', mfaEnrolled: false };

export interface ServiceRow {
  name: string;
  kind: 'Domain' | 'Hosting' | 'VPS';
  status: { label: string; tone: Tone };
  renews: string;
}

export const sampleServices: readonly ServiceRow[] = [
  { name: 'okafordesigns.ng', kind: 'Domain', status: { label: 'Active', tone: 'success' }, renews: '14 Mar 2027' },
  { name: 'okafordesigns.com', kind: 'Domain', status: { label: 'Renew soon', tone: 'warning' }, renews: '2 Nov 2026' },
  { name: 'Starter Hosting · okafordesigns.ng', kind: 'Hosting', status: { label: 'Active', tone: 'success' }, renews: '14 Mar 2027' },
  { name: 'vps-lagos-01 · CX22', kind: 'VPS', status: { label: 'Provisioning', tone: 'info' }, renews: '5 Nov 2026' },
];

export const sampleBalance = money(1_250_000, 'NGN');
export const sampleNextInvoice = { amount: money(1_850_000, 'NGN'), due: '2 Nov 2026' };

export const samplePrices: Record<Tld, Money> = {
  com: money(1_650_000, 'NGN'),
  ng: money(1_500_000, 'NGN'),
  'com.ng': money(700_000, 'NGN'),
  net: money(1_900_000, 'NGN'),
  org: money(1_800_000, 'NGN'),
  co: money(3_500_000, 'NGN'),
};

/** Deterministic fake availability so the prototype is stable in tests and demos. */
export function sampleAvailability(label: string, tld: Tld): boolean {
  let hash = 0;
  for (const ch of `${label}.${tld}`) hash = (hash * 31 + ch.charCodeAt(0)) >>> 0;
  return label.length > 3 && hash % 4 !== 0;
}
