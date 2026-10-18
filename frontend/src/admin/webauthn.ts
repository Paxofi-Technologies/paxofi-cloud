/**
 * Browser side of the staff passkey ceremonies (identity threat model §6.17).
 * The server sends standard WebAuthn JSON options (binary values as base64url)
 * and verifies everything; this file only converts between JSON and the
 * ArrayBuffers navigator.credentials needs. It never sees a private key.
 */

export function base64UrlToBuffer(value: string): ArrayBuffer {
  if (!/^[A-Za-z0-9_-]*$/.test(value)) throw new Error('Not base64url');
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (value.length % 4)) % 4);
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes.buffer;
}

export function bufferToBase64Url(buffer: ArrayBuffer | ArrayBufferView): string {
  const bytes = buffer instanceof ArrayBuffer ? new Uint8Array(buffer) : new Uint8Array(buffer.buffer, buffer.byteOffset, buffer.byteLength);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

type Json = Record<string, unknown>;

function record(value: unknown): Json {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) throw new Error('Malformed options');
  return value as Json;
}

function text(value: unknown): string {
  if (typeof value !== 'string') throw new Error('Malformed options');
  return value;
}

function descriptors(value: unknown): PublicKeyCredentialDescriptor[] {
  if (value === undefined) return [];
  if (!Array.isArray(value)) throw new Error('Malformed options');
  return value.map((entry) => {
    const item = record(entry);
    const descriptor: PublicKeyCredentialDescriptor = { type: 'public-key', id: base64UrlToBuffer(text(item.id)) };
    if (Array.isArray(item.transports)) descriptor.transports = item.transports.filter((t): t is AuthenticatorTransport => typeof t === 'string');
    return descriptor;
  });
}

export function toCreationOptions(json: unknown): PublicKeyCredentialCreationOptions {
  const options = record(json);
  const rp = record(options.rp);
  const user = record(options.user);
  if (!Array.isArray(options.pubKeyCredParams)) throw new Error('Malformed options');
  const selection = options.authenticatorSelection === undefined ? undefined : (record(options.authenticatorSelection) as AuthenticatorSelectionCriteria);

  const result: PublicKeyCredentialCreationOptions = {
    challenge: base64UrlToBuffer(text(options.challenge)),
    rp: { id: text(rp.id), name: text(rp.name) },
    user: { id: base64UrlToBuffer(text(user.id)), name: text(user.name), displayName: text(user.displayName) },
    pubKeyCredParams: options.pubKeyCredParams.map((p) => {
      const param = record(p);
      if (typeof param.alg !== 'number') throw new Error('Malformed options');
      return { type: 'public-key', alg: param.alg };
    }),
    excludeCredentials: descriptors(options.excludeCredentials),
  };
  if (typeof options.timeout === 'number') result.timeout = options.timeout;
  if (typeof options.attestation === 'string') result.attestation = options.attestation as AttestationConveyancePreference;
  if (selection) result.authenticatorSelection = selection;
  return result;
}

export function toRequestOptions(json: unknown): PublicKeyCredentialRequestOptions {
  const options = record(json);
  const result: PublicKeyCredentialRequestOptions = {
    challenge: base64UrlToBuffer(text(options.challenge)),
    allowCredentials: descriptors(options.allowCredentials),
  };
  if (typeof options.rpId === 'string') result.rpId = options.rpId;
  if (typeof options.timeout === 'number') result.timeout = options.timeout;
  if (typeof options.userVerification === 'string') result.userVerification = options.userVerification as UserVerificationRequirement;
  return result;
}

/** The PublicKeyCredential as the JSON the server's verifier reads. */
export function credentialToJson(credential: PublicKeyCredential): Json {
  const response = credential.response;
  const body: Json = { clientDataJSON: bufferToBase64Url(response.clientDataJSON) };
  if ('attestationObject' in response) {
    const attestation = response as AuthenticatorAttestationResponse;
    body.attestationObject = bufferToBase64Url(attestation.attestationObject);
    if (typeof attestation.getTransports === 'function') body.transports = attestation.getTransports();
  } else {
    const assertion = response as AuthenticatorAssertionResponse;
    body.authenticatorData = bufferToBase64Url(assertion.authenticatorData);
    body.signature = bufferToBase64Url(assertion.signature);
    if (assertion.userHandle) body.userHandle = bufferToBase64Url(assertion.userHandle);
  }
  return { id: credential.id, rawId: bufferToBase64Url(credential.rawId), type: credential.type, response: body };
}

export type CeremonyOutcome = { kind: 'ok'; credential: Json } | { kind: 'cancelled' } | { kind: 'unsupported' };

export interface CredentialsApi {
  create(options: CredentialCreationOptions): Promise<Credential | null>;
  get(options: CredentialRequestOptions): Promise<Credential | null>;
}

function browserCredentials(): CredentialsApi | null {
  if (typeof window === 'undefined' || typeof window.PublicKeyCredential === 'undefined' || !navigator.credentials) return null;
  return navigator.credentials;
}

/** A cancelled prompt, a timeout and "no passkey here" all look the same to the browser (NotAllowedError). */
async function run(action: (api: CredentialsApi) => Promise<Credential | null>, api: CredentialsApi | null): Promise<CeremonyOutcome> {
  if (api === null) return { kind: 'unsupported' };
  try {
    const credential = await action(api);
    if (credential === null || credential.type !== 'public-key') return { kind: 'cancelled' };
    return { kind: 'ok', credential: credentialToJson(credential as PublicKeyCredential) };
  } catch {
    return { kind: 'cancelled' };
  }
}

export function createPasskey(options: unknown, api: CredentialsApi | null = browserCredentials()): Promise<CeremonyOutcome> {
  return run((credentials) => credentials.create({ publicKey: toCreationOptions(options) }), api);
}

export function getPasskey(options: unknown, api: CredentialsApi | null = browserCredentials()): Promise<CeremonyOutcome> {
  return run((credentials) => credentials.get({ publicKey: toRequestOptions(options), mediation: 'optional' }), api);
}
