# Provider credentials: storage and key rotation

Provider API tokens (Hetzner, Vultr, registrars, WHM and so on) are stored in
the `provider_credentials` table, **encrypted** with libsodium
XChaCha20-Poly1305 (SRS SEC-004). The plaintext token exists only in a worker's
memory during a provider call. See `docs/security/THREAT-MODEL-VPS-PROVIDERS.md`
(V-01, V-04, D-5).

## Keys

Key-encryption keys (KEKs) come only from the worker environment, never from
the database:

```
PROVIDER_CREDENTIAL_KEYS="k2026a:<base64 32 bytes>"
PROVIDER_CREDENTIAL_ACTIVE_KEY="k2026a"
```

- The **web tier never receives these variables**. Only provisioning workers do.
- Generate a key with:
  `php -r 'require "vendor/autoload.php"; echo PaxofiCloud\Infrastructure\Secrets\KeyRing::generateEntry("k2026a"), PHP_EOL;'`
  Put the output straight into the secret store. Never paste it into chat,
  tickets or commits.
- Every ciphertext is bound to its row (credential ID, provider, environment and
  access level). A ciphertext copied into another row fails to decrypt.

## Rotating the KEK (no downtime)

1. Generate a new entry (for example `k2026b`) and **prepend** it to
   `PROVIDER_CREDENTIAL_KEYS`, keeping the old entry. Set
   `PROVIDER_CREDENTIAL_ACTIVE_KEY=k2026b`. Deploy the workers.
2. Re-encrypt all rows with the new key:
   `MysqlProviderCredentialStore::reencryptAll()`. The CLI command arrives with
   the worker bootstrap. The method is idempotent and only updates rows that
   have not changed since they were read.
3. Confirm `SELECT DISTINCT key_id FROM provider_credentials` returns only
   `k2026b`.
4. Remove the old entry from `PROVIDER_CREDENTIAL_KEYS` and deploy again.

## Rotating a provider token

Create the new token at the provider (least privilege, SEC-005) and save it
under the same credential ID with a new review date. Then revoke the old token
at the provider. Rotate immediately if a token may have leaked, and at the
latest on its `review_due_on` date.
