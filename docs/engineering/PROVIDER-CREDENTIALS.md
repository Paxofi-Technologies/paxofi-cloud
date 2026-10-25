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

## Entering a credential in the admin console (preferred)

Security Admins set or replace the DomainNameAPI, Namecheap, Dynadot, Spaceship, Hetzner and Vultr credentials on the
**API providers** pages of the admin console: **API providers → Domain registrars** (DomainNameAPI,
Namecheap; addresses `/api-providers/domain-registrars`, old `/provider-connections` links land here) and
**API providers → Server providers** (Hetzner, Vultr; `/api-providers/server-providers`). Every field shows
where in the provider's own dashboard to find its value. For Namecheap, **API user** and **User name** are
both the Namecheap account username (not the email address). See
`docs/security/THREAT-MODEL-PROVIDER-CONNECTIONS.md` for the design.

1. Open the page, choose the provider, and type the values.
2. Enter a fresh authenticator code. Every submission needs one, and there are at most 5 tries per 15 minutes.
3. The web tier seals the values to the provider worker's intake public key and stores only that sealed box. It cannot read them back, and neither can anyone else: the page shows status only.
4. Within seconds the `provider-worker` container opens the box, checks it, encrypts the secret under the KEK into `provider_credentials`, and deletes the sealed row.
5. Every staff manager receives a "A provider credential was changed" email naming the provider and time. If nobody expected the change, re-enter the correct credential at once.

The page is visible only to staff with `provider_credentials.manage` (Security Admin).
Each provider shows one of these statuses:

| Status | Meaning |
|---|---|
| Not connected yet | No credential is stored for this environment. |
| Saved …, being encrypted and stored | A sealed submission is waiting for the provider worker. The page checks again every few seconds. |
| Connected. Last set … | A credential is stored. The page shows when it was last set and its review date. |

If a provider stays on "being encrypted and stored" for more than a minute, the
provider worker is not running. Run the Staging Deploy `status` action and check that
the `provider-worker` container is up, then submit again with a new code. Rejections are recorded in the audit log.

DomainNameAPI takes two values from the reseller panel (My Account > Reseller Settings >
Integration Information): the **Reseller ID** and an **API key**. There is no password. Staging
and production both use the **Live Environment** key (CEO decision, 24 Oct 2026), so staging sees
real cost prices. Staging may only **read** (extensions, prices, availability, balance): every
registrar call that spends money or changes a domain is refused unless `REGISTRAR_WRITES=enabled`,
which is off outside production (THREAT-MODEL-DOMAIN-REGISTRAR.md, DR-4). Keep the reseller
balance small until launch. If a key is replaced in the panel, enter the new one here again.

Namecheap takes four values (THREAT-MODEL-DOMAIN-PRICING.md, NC-3): the **API user**, the
**user name** (usually the same), the **API key** (Profile > Tools > Business & Dev Tools > API
Access) and the **server IPv4 address** that calls Namecheap. Namecheap answers only from
addresses whitelisted in that same API Access page, so:

1. Find the staging server's public IPv4 address (Vultr dashboard > the staging instance).
2. Add it to Namecheap's API whitelist.
3. Enter the same address in the connection's "Server IPv4 address" field.

Whenever the staging server is replaced (infra apply), its address changes: update both the
whitelist and the connection, or the Namecheap price sync stops with a "credential refused"
alert while the last good prices stay in use. Only a public IPv4 address is accepted. The values
travel to Namecheap only in the POST body, never in a URL.

Dynadot takes two values (THREAT-MODEL-DOMAIN-PRICING.md, DY-2): the **API key** and the **API
secret**, both from Tools > API in the Dynadot account. Use the production key, not the sandbox
key. The key is sent as a bearer token; the secret never leaves the provider worker and only signs
each request. Dynadot no longer requires an IP whitelist for the RESTful API; if the account has
one, add the staging server's IPv4 address to it.

Spaceship takes two values (THREAT-MODEL-DOMAIN-PRICING.md, SP-1, SP-2): the **API key** and the
**API secret**, from API Manager in the Spaceship account. Create a key for PaxofiCloud with **only
the `domains:read` scope** and check the scope list before entering it: no write, transfer, billing,
contacts or DNS access until registration through Spaceship goes live (then a new key). Never enter
a full-access key. The secret is shown once, when the key is created. Both values travel only in
request headers (`X-API-Secret`, `X-API-Key`).

Credential IDs are fixed per environment, for example `domainnameapi-staging` and
`hetzner-prod`. A credential stored this way gets a review date one year ahead.

On staging, the deploy agent generates the intake key pair and the KEK on the host
the first time it releases. The web tier gets only `credential-intake-public.env`;
the provider worker alone gets `credential-intake-secret.env` and
`provider-credential-keys.env`.

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
