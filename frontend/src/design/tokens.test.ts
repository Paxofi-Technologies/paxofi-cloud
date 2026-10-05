import { describe, expect, it } from 'vitest';
import tokens from './tokens.json';

type Node = { [key: string]: Node | unknown };

function lookup(path: string): string {
  let node: unknown = tokens;
  for (const part of path.split('.')) node = (node as Node)[part];
  const value = (node as { $value: unknown }).$value;
  if (typeof value !== 'string') throw new Error(`Token ${path} is not a string`);
  const alias = /^\{(.+)\}$/.exec(value);
  return alias?.[1] ? lookup(alias[1]) : value;
}

function luminance(hex: string): number {
  const match = /^#([0-9a-f]{6})$/i.exec(hex);
  if (!match?.[1]) throw new Error(`Expected #RRGGBB, got ${hex}`);
  const n = parseInt(match[1], 16);
  const channel = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel((n >> 16) & 255) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(lookup(a)), luminance(lookup(b))].sort((x, y) => y - x) as [number, number];
  return (hi + 0.05) / (lo + 0.05);
}

// WCAG 2.2 AA: 4.5:1 for body text, 3:1 for UI components and focus indicators.
const TEXT_PAIRS: [string, string][] = [
  ['semantic.text.primary', 'semantic.surface.card'],
  ['semantic.text.primary', 'semantic.surface.page'],
  ['semantic.text.secondary', 'semantic.surface.card'],
  ['semantic.text.secondary', 'semantic.surface.page'],
  ['semantic.text.link', 'semantic.surface.card'],
  ['semantic.text.brand', 'semantic.surface.card'],
  ['semantic.text.inverse', 'semantic.surface.inverse'],
  ['semantic.nav.text', 'semantic.surface.inverse'],
  ['semantic.nav.textMuted', 'semantic.surface.inverse'],
  ['semantic.action.onPrimary', 'semantic.action.primary'],
  ['semantic.action.onPrimary', 'semantic.action.primaryHover'],
  ['semantic.action.onPrimary', 'semantic.action.danger'],
  ['semantic.action.onSecondary', 'semantic.action.secondary'],
  ['semantic.feedback.successText', 'semantic.feedback.successSurface'],
  ['semantic.feedback.warningText', 'semantic.feedback.warningSurface'],
  ['semantic.feedback.dangerText', 'semantic.feedback.dangerSurface'],
  ['semantic.feedback.dangerText', 'semantic.surface.card'],
  ['semantic.feedback.infoText', 'semantic.feedback.infoSurface'],
  ['semantic.feedback.accentText', 'semantic.feedback.accentSurface'],
];

const UI_PAIRS: [string, string][] = [
  ['semantic.border.input', 'semantic.surface.card'],
  ['semantic.focus.ring', 'semantic.surface.card'],
  ['semantic.focus.ring', 'semantic.surface.page'],
];

describe('design tokens meet WCAG 2.2 AA contrast', () => {
  it.each(TEXT_PAIRS)('%s on %s ≥ 4.5:1', (fg, bg) => {
    expect(contrast(fg, bg)).toBeGreaterThanOrEqual(4.5);
  });

  it.each(UI_PAIRS)('%s on %s ≥ 3:1', (fg, bg) => {
    expect(contrast(fg, bg)).toBeGreaterThanOrEqual(3);
  });

  it('keeps the PKDMS brand colours unchanged', () => {
    expect(lookup('color.brand.navy')).toBe('#0B1F3A');
    expect(lookup('color.brand.blue')).toBe('#2F6BFF');
    expect(lookup('color.brand.teal')).toBe('#00BFA6');
  });
});
