/**
 * Minimal client for the PaxofiCloud API. Same-origin only: the browser sends
 * our Origin with every POST, which the API requires (threat model T-48), and
 * JSON bodies need a CORS preflight that the API never grants to other sites.
 */
export type ApiResult =
  | { kind: 'ok'; status: number; data: unknown }
  | { kind: 'invalid'; errors: Record<string, string> }
  | { kind: 'throttled'; retryAfterSeconds: number }
  | { kind: 'rejected'; code: string }
  | { kind: 'unavailable' };

export async function postJson(
  path: string,
  body: unknown,
  fetchImpl: typeof fetch = fetch,
  headers: Record<string, string> = {},
): Promise<ApiResult> {
  let response: Response;
  try {
    response = await fetchImpl(path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { ...headers, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    });
  } catch {
    return { kind: 'unavailable' };
  }

  if (response.ok) return { kind: 'ok', status: response.status, data: await readJson(response) };
  if (response.status === 429) {
    const retry = Number.parseInt(response.headers.get('retry-after') ?? '', 10);
    return { kind: 'throttled', retryAfterSeconds: Number.isFinite(retry) && retry > 0 ? retry : 3600 };
  }
  if (response.status >= 500) {
    // A fail-closed bot check (threat model §6.12) is the only 5xx the forms explain.
    const problem = await readProblem(response);
    return problem.code === 'challenge_unavailable' ? { kind: 'rejected', code: problem.code } : { kind: 'unavailable' };
  }

  const problem = await readProblem(response);
  if (response.status === 422 && problem.errors) return { kind: 'invalid', errors: problem.errors };

  return { kind: 'rejected', code: problem.code ?? `http_${response.status}` };
}

async function readJson(response: Response): Promise<unknown> {
  if (!(response.headers.get('content-type') ?? '').includes('json')) return null;
  try {
    return (await response.json()) as unknown;
  } catch {
    return null;
  }
}

async function readProblem(response: Response): Promise<{ code?: string; errors?: Record<string, string> }> {
  try {
    const data: unknown = await response.json();
    if (typeof data !== 'object' || data === null) return {};
    const record = data as Record<string, unknown>;
    const result: { code?: string; errors?: Record<string, string> } = {};
    if (typeof record.code === 'string') result.code = record.code;
    if (typeof record.errors === 'object' && record.errors !== null) {
      result.errors = Object.fromEntries(
        Object.entries(record.errors as Record<string, unknown>).filter((entry): entry is [string, string] => typeof entry[1] === 'string'),
      );
    }
    return result;
  } catch {
    return {};
  }
}

/** "about 2 hours", "about 5 minutes" — for 429 messages. */
export function describeWait(seconds: number): string {
  if (seconds >= 5400) return `about ${Math.round(seconds / 3600)} hours`;
  if (seconds >= 3000) return 'about an hour';
  const minutes = Math.max(1, Math.ceil(seconds / 60));
  return minutes === 1 ? 'a minute' : `${minutes} minutes`;
}
