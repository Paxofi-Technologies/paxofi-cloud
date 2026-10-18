import { useEffect, useState } from 'react';

/**
 * Cloudflare Turnstile bot check (identity threat model §6.12). The server
 * decides whether a form needs it and verifies every token itself; the browser
 * only renders the widget and forwards the token with the form.
 */
export const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

export type ChallengeAction = 'register' | 'resend' | 'sign_in';

export interface TurnstileApi {
  render: (
    container: HTMLElement,
    options: {
      sitekey: string;
      action: ChallengeAction;
      callback: (token: string) => void;
      'expired-callback': () => void;
      'error-callback': () => void;
      'timeout-callback': () => void;
    },
  ) => string | undefined;
  reset: (widgetId: string) => void;
  remove: (widgetId: string) => void;
}

declare global {
  interface Window {
    turnstile?: TurnstileApi;
  }
}

let loading: Promise<TurnstileApi> | null = null;

/** Adds Cloudflare's script once; resolves when `window.turnstile` exists. */
export function loadTurnstile(): Promise<TurnstileApi> {
  if (window.turnstile) return Promise.resolve(window.turnstile);
  loading ??= new Promise<TurnstileApi>((resolve, reject) => {
    const script = document.createElement('script');
    script.src = TURNSTILE_SCRIPT;
    script.async = true;
    script.onload = () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('Turnstile did not load')));
    script.onerror = () => {
      loading = null;
      reject(new Error('Turnstile did not load'));
    };
    document.head.appendChild(script);
  });
  return loading;
}

/**
 * The public site key; null when the server has no challenge configured;
 * undefined while still asking.
 */
export function useChallengeSiteKey(fetchImpl: typeof fetch = fetch): string | null | undefined {
  const [siteKey, setSiteKey] = useState<string | null | undefined>(undefined);

  useEffect(() => {
    let active = true;
    fetchImpl('/api/v1/auth/challenge', { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then((response) => (response.ok ? (response.json() as Promise<unknown>) : null))
      .then((data) => {
        const key = typeof data === 'object' && data !== null ? (data as Record<string, unknown>).turnstile_site_key : null;
        if (active) setSiteKey(typeof key === 'string' && key !== '' ? key : null);
      })
      .catch(() => {
        // The server still enforces the check; without a key the form will be told to retry.
        if (active) setSiteKey(null);
      });
    return () => {
      active = false;
    };
  }, [fetchImpl]);

  return siteKey;
}

/** Customer-facing text for the two challenge refusals, or null for any other code. */
export function challengeProblem(code: string): string | null {
  if (code === 'challenge_required') return 'Please complete the security check, then try again.';
  if (code === 'challenge_unavailable') return 'Our security check is unavailable right now. Please try again in a minute.';
  return null;
}
