import { describe, expect, it, vi } from 'vitest';
import { base64UrlToBuffer, bufferToBase64Url, createPasskey, credentialToJson, getPasskey, toCreationOptions, toRequestOptions, type CredentialsApi } from './webauthn';

const bytes = (...values: number[]) => new Uint8Array(values).buffer;

// The JSON the server sends (shape of web-auth/webauthn-lib's serialisation).
const creationJson = {
  challenge: 'n5wz6YYcI9AHzs8UV4we5mZRW_F-vWw-kmdkJawU-XY',
  timeout: 300000,
  rp: { id: 'admin.example.test', name: 'PaxofiCloud' },
  user: { id: '3InPPgNvr2StjcUgVys-iu_mZ_MHpcTq5xbJ6i-0lX0', name: 'ada@example.ng', displayName: 'Ada' },
  pubKeyCredParams: [
    { type: 'public-key', alg: -7 },
    { type: 'public-key', alg: -8 },
  ],
  authenticatorSelection: { requireResidentKey: true, userVerification: 'required', residentKey: 'required' },
  attestation: 'none',
  excludeCredentials: [{ type: 'public-key', id: 'sD5Edez5ELivZxK7Or7bcg' }],
};

const requestJson = { challenge: 'm_PXpR3SoAs9-2ppvQBWhQkmO3j-lJsCfvRnPEm4PhQ', timeout: 300000, rpId: 'admin.example.test', allowCredentials: [], userVerification: 'required' };

describe('base64url', () => {
  it('round-trips every byte value without padding or + /', () => {
    const all = new Uint8Array(256).map((_, i) => i);
    const encoded = bufferToBase64Url(all);
    expect(encoded).toMatch(/^[A-Za-z0-9_-]+$/);
    expect(new Uint8Array(base64UrlToBuffer(encoded))).toEqual(all);
    expect(bufferToBase64Url(bytes(0xfb, 0xff))).toBe('-_8');
  });

  it('refuses anything that is not base64url', () => {
    expect(() => base64UrlToBuffer('ab+/')).toThrow();
    expect(() => base64UrlToBuffer('ab==')).toThrow();
  });
});

describe('WebAuthn options', () => {
  it('converts creation options, keeping the server policy (UV + discoverable, ES256/EdDSA only)', () => {
    const options = toCreationOptions(creationJson);
    expect(bufferToBase64Url(options.challenge as ArrayBuffer)).toBe(creationJson.challenge);
    expect(bufferToBase64Url(options.user.id as ArrayBuffer)).toBe(creationJson.user.id);
    expect(options.rp).toEqual({ id: 'admin.example.test', name: 'PaxofiCloud' });
    expect(options.pubKeyCredParams).toEqual([
      { type: 'public-key', alg: -7 },
      { type: 'public-key', alg: -8 },
    ]);
    expect(options.authenticatorSelection).toEqual(creationJson.authenticatorSelection);
    expect(options.attestation).toBe('none');
    expect(bufferToBase64Url(options.excludeCredentials?.[0]?.id as ArrayBuffer)).toBe('sD5Edez5ELivZxK7Or7bcg');
  });

  it('converts usernameless request options', () => {
    const options = toRequestOptions(requestJson);
    expect(bufferToBase64Url(options.challenge as ArrayBuffer)).toBe(requestJson.challenge);
    expect(options.allowCredentials).toEqual([]);
    expect(options.rpId).toBe('admin.example.test');
    expect(options.userVerification).toBe('required');
  });

  it('rejects malformed options', () => {
    expect(() => toCreationOptions({ ...creationJson, user: null })).toThrow();
    expect(() => toRequestOptions({ challenge: 42 })).toThrow();
  });
});

function attestationCredential() {
  return {
    id: 'AQID',
    rawId: bytes(1, 2, 3),
    type: 'public-key',
    response: { clientDataJSON: bytes(123, 125), attestationObject: bytes(9, 9), getTransports: () => ['usb'] },
  } as unknown as PublicKeyCredential;
}

function assertionCredential() {
  return {
    id: 'AQID',
    rawId: bytes(1, 2, 3),
    type: 'public-key',
    response: { clientDataJSON: bytes(123, 125), authenticatorData: bytes(7), signature: bytes(8), userHandle: bytes(5) },
  } as unknown as PublicKeyCredential;
}

describe('credentialToJson', () => {
  it('serialises a registration response', () => {
    expect(credentialToJson(attestationCredential())).toEqual({
      id: 'AQID',
      rawId: 'AQID',
      type: 'public-key',
      response: { clientDataJSON: 'e30', attestationObject: 'CQk', transports: ['usb'] },
    });
  });

  it('serialises a sign-in response with the user handle', () => {
    expect(credentialToJson(assertionCredential())).toEqual({
      id: 'AQID',
      rawId: 'AQID',
      type: 'public-key',
      response: { clientDataJSON: 'e30', authenticatorData: 'Bw', signature: 'CA', userHandle: 'BQ' },
    });
  });
});

describe('ceremonies', () => {
  it('reports a browser without WebAuthn as unsupported', async () => {
    await expect(createPasskey(creationJson, null)).resolves.toEqual({ kind: 'unsupported' });
    await expect(getPasskey(requestJson, null)).resolves.toEqual({ kind: 'unsupported' });
  });

  it('treats a cancelled prompt (NotAllowedError) or no credential as cancelled', async () => {
    const api: CredentialsApi = {
      create: vi.fn(() => Promise.reject(new DOMException('cancelled', 'NotAllowedError'))),
      get: vi.fn(() => Promise.resolve(null)),
    };
    await expect(createPasskey(creationJson, api)).resolves.toEqual({ kind: 'cancelled' });
    await expect(getPasskey(requestJson, api)).resolves.toEqual({ kind: 'cancelled' });
  });

  it('passes converted options to the browser and returns the credential JSON', async () => {
    const api: CredentialsApi = { create: vi.fn(() => Promise.resolve(attestationCredential())), get: vi.fn(() => Promise.resolve(assertionCredential())) };
    const created = await createPasskey(creationJson, api);
    expect(created.kind).toBe('ok');
    const createArg = (api.create as ReturnType<typeof vi.fn>).mock.calls[0]?.[0] as CredentialCreationOptions;
    expect(createArg.publicKey?.rp.id).toBe('admin.example.test');

    const got = await getPasskey(requestJson, api);
    expect(got).toEqual({ kind: 'ok', credential: credentialToJson(assertionCredential()) });
    const getArg = (api.get as ReturnType<typeof vi.fn>).mock.calls[0]?.[0] as CredentialRequestOptions;
    expect(getArg.publicKey?.userVerification).toBe('required');
  });
});
