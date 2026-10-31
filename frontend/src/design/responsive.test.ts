import { describe, expect, it } from 'vitest';
import screens from './screens.css?raw';

/**
 * Phone layout rules that a browser-only bug broke once (Billing was 1001 px
 * wide on a 375 px phone): jsdom cannot measure layout, so the rules themselves
 * are pinned here. Checked by hand with Playwright at 320, 360, 375 and 414 px.
 */
function rule(selector: string): string {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`${escaped}\\s*\\{([^}]*)\\}`).exec(screens)?.[1] ?? '';
}

describe('phone layout', () => {
  it('keeps absolutely positioned labels inside a table scroll box', () => {
    expect(rule('.pc-table-scroll')).toMatch(/overflow-x:\s*auto/);
    expect(rule('.pc-table-scroll')).toMatch(/position:\s*relative/);
  });

  it('lets the single-column grid shrink below its content', () => {
    expect(screens).toMatch(/@media \(max-width: 1100px\) \{ \.pc-grid-2 \{ grid-template-columns: minmax\(0, 1fr\); \} \}/);
  });

  it('lets a long amount in a stat tile wrap on phones', () => {
    expect(screens).toMatch(/@media \(max-width: 480px\) \{[^@]*\.pc-stat__value \{[^}]*overflow-wrap: anywhere/);
  });
});
