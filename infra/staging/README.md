# Staging infrastructure (OpenTofu)

This directory creates the PaxofiCloud **staging** environment. Staging runs on
Vultr until the Hetzner account is approved, with DNS and edge protection on
Cloudflare. It provisions the host and the Cloudflare Tunnel; the application
is released by the **PaxofiCloud Staging Deploy** workflow
(`.github/workflows/deploy-staging.yml`, design in
`docs/security/THREAT-MODEL-DEPLOY-PIPELINE.md`, option B).

## What it creates

| Resource | Purpose |
|---|---|
| `vultr_instance.staging` | Ubuntu 24.04 host (default plan `vc2-2c-4gb`, region `jnb`), IPv6 on, configured by `cloud-init.yaml.tftpl`: Docker, `cloudflared`, the deploy agent and the runtime `compose.yaml` |
| `vultr_firewall_group.staging` | **No rules: every inbound packet is dropped.** The host only dials out. |
| `vultr_ssh_key.admin` | Admin public key on the instance record (break-glass via the Vultr console only; sshd listens on loopback) |
| `cloudflare_zero_trust_tunnel_cloudflared.staging` + `_config` | Outbound-only tunnel: `cloud-staging.<zone>` → Nginx on `127.0.0.1:8080`; `cloud-staging-ssh.<zone>` → sshd on `127.0.0.1:22`; everything else 404 |
| `cloudflare_dns_record.web` / `.ssh` | Proxied CNAMEs to the tunnel (no origin IP is ever published) |
| `cloudflare_zero_trust_access_application.ssh` + policy + service token | Only the CI service token (90-day expiry) can open the SSH hostname |

The hostname must be one label deep (`cloud-staging`, not `staging.cloud`).
Cloudflare's free Universal SSL covers only `*.<zone>`, and the variable
validation enforces this.

## Security model

- **No inbound ports.** The firewall drops everything; `cloudflared` connects
  out to Cloudflare. The origin IP is useless to an attacker, and the
  Cloudflare WAF, rate limits and TLS policy cannot be bypassed.
- **Deploy access** goes SSH → Cloudflare Access (service token only) → tunnel →
  sshd on loopback. The `deploy` user's key is restricted to a forced command,
  `/usr/local/sbin/paxoficloud-deploy`, which accepts only `load`, `release
  <sha>` and `status`. No shell, TTY or forwarding. The Vultr web console is the
  break-glass path.
- **Host hardening (cloud-init):**
  - root login and password authentication off; `AllowUsers deploy`
  - unattended security upgrades
  - containers cannot reach the cloud metadata address (`DOCKER-USER` drop), so
    the tunnel token in user data stays out of reach
  - Docker with `no-new-privileges` and bounded logs
  - kernel network hardening
- **State is encrypted client-side** (OpenTofu `encryption`, PBKDF2 + AES-GCM,
  `enforced = true`). The state bucket never sees resource IDs, IPs or the
  instance's generated root password in clear text. Plans are encrypted the same way.
- **Supply chain:**
  - The OpenTofu binary is verified by SHA-256 in CI.
  - Providers are pinned in `.terraform.lock.hcl` (`h1:` and `zh:` hashes; the `zh:` values match the providers' published `SHA256SUMS`).
  - CI runs `tofu init -lockfile=readonly`.
- **Credentials:**
  - All credentials are GitHub **Environment** secrets on `staging`, which requires the CEO's approval before any plan or apply runs.
  - The Vultr key belongs to a dedicated least-privilege IaC user with an expiry date.
  - The Cloudflare token is scoped to `Zone:DNS:Edit` and `Zone:Zone:Read` on one zone, plus account-level `Cloudflare Tunnel:Edit`, `Access: Apps and Policies:Edit` and `Access: Service Tokens:Edit`.
  - The production app will use separate keys.

## One-time setup (GitHub → Settings → Environments → `staging`)

**Secrets**

| Name | Value |
|---|---|
| `VULTR_API_KEY` | API key of the Vultr `iac-staging` user |
| `CLOUDFLARE_API_TOKEN` | Token with `Zone:DNS:Edit` + `Zone:Zone:Read` on the zone, and account `Cloudflare Tunnel:Edit`, `Access: Apps and Policies:Edit`, `Access: Service Tokens:Edit` |
| `STAGING_DEPLOY_SSH_KEY` | Private half of the deploy key (used only by the deploy workflow) |
| `TF_STATE_PASSPHRASE` | Random passphrase, ≥ 32 characters (e.g. `openssl rand -base64 32`). **Store a copy in the company password manager.** Losing it makes the state unreadable. |
| `STATE_S3_ACCESS_KEY_ID` | Access key for the state bucket |
| `STATE_S3_SECRET_ACCESS_KEY` | Secret key for the state bucket |
| `STATE_S3_ENDPOINT` | S3 endpoint: R2 `https://<account-id>.r2.cloudflarestorage.com`, or Vultr Object Storage `https://<region>.vultrobjects.com`. A secret so the account ID is masked in public logs. |

**Variables** (not secret)

| Name | Value |
|---|---|
| `STATE_BUCKET` | Bucket name, e.g. `paxoficloud-tfstate` |
| `STAGING_ADMIN_SSH_PUBLIC_KEY` | An `ssh-ed25519 …` public key (`ssh-keygen -t ed25519 -C paxoficloud-staging`) |
| `STAGING_DEPLOY_SSH_PUBLIC_KEY` | Public half of the deploy key: `ssh-keygen -t ed25519 -N '' -C paxoficloud-staging-deploy -f deploy_key`. Put `deploy_key` in the secret above, then delete both local files. |

**State bucket:** Cloudflare R2's free tier is enough.
1. Create a private bucket.
2. Create an R2 API token with **Object Read & Write** on that bucket only.
3. Vultr Object Storage also works but is billed monthly.

## Running

GitHub → Actions → **PaxofiCloud Staging Infrastructure** → **Run workflow**.

1. Run `plan` first and read the plan in the job log.
2. Run `apply`. Each run waits for the environment approval. Changing the
   cloud-init template, the agent or `deploy/staging/compose.yaml` replaces
   the instance; staging data does not survive that, by design.
3. Release the application: Actions → **PaxofiCloud Staging Deploy** → Run
   workflow on `main`. It builds the images, smoke-tests them, streams them to
   the host and runs `release`, which migrates, starts, health-checks and rolls
   back automatically on failure.
4. To switch staging off and stop paying for the server, run `destroy` and type
   `destroy-staging` in the confirmation box. This removes the server, its
   firewall, the tunnel, the Access application and token, the SSH key and the
   DNS records. Run `apply` again to bring it back.

Never delete or change these resources in the Vultr or Cloudflare dashboards.
OpenTofu would then hold a stale record of them. If something was deleted by
hand, run `destroy` (or `apply`) and the next run reconciles the state.

**Public logs.** This repository is public, so workflow logs are public. Plan and apply print only resource addresses, actions and the summary line, never attribute values such as the origin IP. If a plan needs closer inspection, an operator runs it locally with the same credentials.

Pull requests that touch `infra/` run `fmt` and `validate` only. Those runs have no credentials and don't touch the state.

## Local validation

```sh
cd infra/staging
TF_VAR_state_passphrase=validate-only-placeholder TF_VAR_vultr_api_key=x \
  tofu init -backend=false && tofu validate
```
