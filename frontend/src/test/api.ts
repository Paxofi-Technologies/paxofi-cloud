import { vi } from 'vitest';

export const CHALLENGE_PATH = '/api/v1/auth/challenge';

export type FetchMock = typeof fetch & ReturnType<typeof vi.fn>;

/**
 * A fetch stand-in: the challenge-config request answers with `siteKey`
 * (null = no bot check) and every other request with the given response.
 */
export function api(status: number, body: unknown = {}, headers: Record<string, string> = {}, siteKey: string | null = null): FetchMock {
  return vi.fn((path: string) =>
    Promise.resolve(
      path === CHALLENGE_PATH
        ? new Response(JSON.stringify({ turnstile_site_key: siteKey }), { status: 200, headers: { 'content-type': 'application/json' } })
        : new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } }),
    ),
  ) as unknown as FetchMock;
}

/** The calls a form made, leaving out the challenge-config request. */
export function formCalls(fetchImpl: FetchMock): [string, RequestInit][] {
  return (fetchImpl.mock.calls as [string, RequestInit][]).filter(([path]) => path !== CHALLENGE_PATH);
}
