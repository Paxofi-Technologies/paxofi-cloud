# ADR-002 — WebAuthn verification library for staff sign-in

- **Status:** Accepted (CEO decision, 18 Oct 2026)
- **Scope:** Staff authentication (SRS IAM-008, ACC-005; identity threat model D-10, T-29, T-30; closes open item O-1)
- **Date:** 2026-10-18

## Context

Staff must sign in with WebAuthn only (passkeys or security keys). Verifying a WebAuthn ceremony means parsing CBOR, COSE keys and attestation statements and checking signatures. The company rule is that we never write our own cryptography, and threat model D-10 asks for a vetted, maintained library rather than our own parsers.

Candidates assessed on 18 Oct 2026:

| Option | Maintained | Dependencies | Notes |
|---|---|---|---|
| `web-auth/webauthn-lib` 5.x | Yes (5.3.9, Sep 2026) | ~12 libraries, including some Symfony components (not the framework) and `spomky-labs/cbor-php`, `web-auth/cose-lib` | Most complete PHP implementation; ES256, EdDSA, RS256; attestation and sign-count handling; wide use |
| `lbuchs/webauthn` 2.x | Last release Jul 2024 | None | Small and readable, but two years without releases for a security-critical component |
| Own minimal verifier | — | None | Signature checks via ext-openssl/libsodium, but CBOR/COSE/attestation parsing is security-critical code we would own |

## Decision

Use **`web-auth/webauthn-lib` 5.x**, pinned to an exact version in `composer.lock` (hash-locked), behind an application contract so the library appears only in `src/Integration`. The application layer never sees library types.

Required settings (D-10): `userVerification = required`; relying-party ID pinned to the admin origin; allowed algorithms ES256 and EdDSA only; sign-count regression rejects the assertion and raises an alert (T-30); attestation conveyance `none` with the authenticator AAGUID recorded per credential.

## Consequences

- **Supply chain:** the dependency set grows. Mitigations: exact pins and lock-file hashes, GitHub Dependency Review on every PR, Dependabot grouped updates, and the library's transitive Symfony components are libraries only (no kernel, no container, no routing), so the no-framework rule holds.
- **Upgrades:** a major-version bump of the library or of its CBOR/COSE dependencies needs a short review note in the PR (what changed in verification).
- **Exit:** because the library sits behind our contract, it can be replaced (for example by our own verifier) without touching the application layer.
