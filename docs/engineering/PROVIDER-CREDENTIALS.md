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

## Operator commands

Run these on a worker host, which has the key variables. The web tier does not.

| Command | What it does | Exit codes |
|---|---|---|
| `credentials:set <id> <provider> <env> <access> <review-due-on>` | Stores or replaces a token, encrypted. The token is read from **standard input only**. | 0 stored; 1 invalid input, missing keys, or a metadata change |
| `credentials:list` | Lists ID, provider, environment, access level, key ID and review date. It never prints the token or the ciphertext. | 0 all fine; 2 a review is overdue or a row is on an old key |
| `credentials:reencrypt` | Rotation step 2: re-encrypts every row that is on an older key. | 0 done; 1 a key is missing or rows remain |

How `credentials:set` handles its input:
- `<env>` is `dev`, `staging` or `prod`, and `<access>` is `read-only` or `read-write`.
- The review date must be after today and no more than one year ahead.
- The token must be printable ASCII with no spaces, at most 2048 bytes.
- The command refuses to run if standard input is an interactive terminal, so the token is never echoed to the screen.
- **Never pass the token as an argument.** Arguments are visible in `ps` and in shell history.

```sh
# Bash: the token is typed without echo and never appears in history or `ps`.
read -rs TOKEN && printf '%s\n' "$TOKEN" | bin/paxoficloud credentials:set hetzner-prod-customers hetzner prod read-write 2027-04-01; unset TOKEN
```

Changing a credential's provider, environment or access level is refused. Create a new credential ID instead. This stops a read-only slot from being quietly upgraded to read/write.

## Rotating the KEK (no downtime)

1. Generate a new entry (for example `k2026b`) and **prepend** it to
   `PROVIDER_CREDENTIAL_KEYS`, keeping the old entry. Set
   `PROVIDER_CREDENTIAL_ACTIVE_KEY=k2026b`. Deploy the workers.
2. Re-encrypt all rows with the new key: `bin/paxoficloud credentials:reencrypt`.
   The command is idempotent. It updates only rows that have not changed since
   they were read.
3. Confirm that `bin/paxoficloud credentials:list` exits with 0 and shows only
   `k2026b` in the KEY column.
4. Remove the old entry from `PROVIDER_CREDENTIAL_KEYS` and deploy again.

## Rotating a provider token

Create the new token at the provider (least privilege, SEC-005). Save it under
the same credential ID with a new review date, using `credentials:set`. Then revoke the old token
at the provider. Rotate immediately if a token may have leaked, and at the
latest on its `review_due_on` date.
