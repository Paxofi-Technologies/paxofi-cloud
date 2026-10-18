import { vi } from 'vitest';
import type { TurnstileApi } from '../lib/challenge';

type RenderOptions = Parameters<TurnstileApi['render']>[1];

/** A stand-in for Cloudflare's `window.turnstile`; `solve()` plays the widget. */
export function fakeTurnstile() {
  const rendered: RenderOptions[] = [];
  const api = {
    render: vi.fn((_el: HTMLElement, options: RenderOptions) => {
      rendered.push(options);
      return `widget-${rendered.length}`;
    }),
    reset: vi.fn(),
    remove: vi.fn(),
  };
  window.turnstile = api;

  return {
    api,
    rendered,
    solve(token: string) {
      const options = rendered.at(-1);
      if (!options) throw new Error('No widget rendered');
      options.callback(token);
    },
    uninstall() {
      delete window.turnstile;
    },
  };
}
