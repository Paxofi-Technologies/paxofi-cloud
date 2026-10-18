import { describe, expect, it, vi } from 'vitest';
import { completeSignIn, groupSecret, readRecoveryCodes, readSetup } from './mfa';

describe('mfa client (§6.13)', () => {
  it('accepts only a well-formed setup response', () => {
    expect(readSetup({ secret: 'ABC', otpauth_uri: 'otpauth://totp/PaxofiCloud:x?secret=ABC' })).toEqual({ secret: 'ABC', uri: 'otpauth://totp/PaxofiCloud:x?secret=ABC' });
    expect(readSetup({ secret: 'ABC', otpauth_uri: 'javascript:alert(1)' })).toBeNull();
    expect(readSetup({ secret: 1 })).toBeNull();
  });

  it('reads recovery codes only as a list of strings', () => {
    expect(readRecoveryCodes({ recovery_codes: ['A', 'B'] })).toEqual(['A', 'B']);
    expect(readRecoveryCodes({ recovery_codes: ['A', 2] })).toBeNull();
    expect(readRecoveryCodes(null)).toBeNull();
  });

  it('groups the manual key in fours', () => {
    expect(groupSecret('GEZDGNBVGY3TQOJQ')).toBe('GEZD GNBV GY3T QOJQ');
  });

  it('sends exactly one factor in the second sign-in step', async () => {
    const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 }))) as unknown as typeof fetch & ReturnType<typeof vi.fn>;
    await completeSignIn({ kind: 'recovery', value: 'ABCD-EFGH-IJKL-MNOP' }, fetchImpl);
    const [path, init] = fetchImpl.mock.calls[0] as [string, RequestInit];
    expect(path).toBe('/api/v1/auth/sign-in/mfa');
    expect(JSON.parse(String(init.body))).toEqual({ recovery_code: 'ABCD-EFGH-IJKL-MNOP' });
  });
});
