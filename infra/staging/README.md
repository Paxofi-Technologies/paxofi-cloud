# Staging infrastructure (OpenTofu)

This directory creates the PaxofiCloud **staging** environment. Staging runs on
Vultr until the Hetzner account is approved, with DNS and edge protection on
Cloudflare. It provisions the host only; deploying the application is a
separate pipeline step.

## What it creates

| Resource | Purpose |
|---|---|
| `vultr_instance.staging` | Ubuntu 24.04 host (default plan `vc2-2c-4gb`, region `jnb`), IPv6 on, hardened by `cloud-init.yaml` |
| `vultr_firewall_group.staging` + rules | Inbound **443 from Cloudflare edge ranges only**; SSH only from `admin_ssh_cidrs` (closed by default) |
| `vultr_ssh_key.admin` | Public key for the `deploy` user (key-only login, root login disabled) |
| `cloudflare_dns_record.staging_v4/v6` | `cloud-staging.<zone>` A/AAAA, **proxied** through Cloudflare |

The hostname must be one label deep (`cloud-staging`, not `staging.cloud`).
Cloudflare's free Universal SSL covers only `*.<zone>`, and the variable
validation enforces this.

## Security model

- **Origin is not directly reachable over HTTP(S).** Only Cloudflare's published
  ranges can reach port 443, so the WAF, rate limits and TLS policy in Cloudflare
  cannot be bypassed via the origin IP.
- **SSH is closed by default.** To open it temporarily, set `admin_ssh_cidrs`
  (single /32s; `0.0.0.0/x` is rejected). The Vultr web console is the
  break-glass path.
- **Host hardening (cloud-init):**
  - root login and password authentication off; `AllowUsers deploy`
  - unattended security upgrades and fail2ban
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
  - The Cloudflare token is scoped to `Zone:DNS:Edit` and `Zone:Zone:Read` on one zone.
  - The production app will use separate keys.

## One-time setup (GitHub → Settings → Environments → `staging`)

**Secrets**

| Name | Value |
|---|---|
| `VULTR_API_KEY` | API key of the Vultr `iac-staging` user |
| `CLOUDFLARE_API_TOKEN` | Token with `Zone:DNS:Edit` + `Zone:Zone:Read` on the zone only |
| `TF_STATE_PASSPHRASE` | Random passphrase, ≥ 32 characters (e.g. `openssl rand -base64 32`). **Store a copy in the company password manager.** Losing it makes the state unreadable. |
| `STATE_S3_ACCESS_KEY_ID` | Access key for the state bucket |
| `STATE_S3_SECRET_ACCESS_KEY` | Secret key for the state bucket |
| `STATE_S3_ENDPOINT` | S3 endpoint: R2 `https://<account-id>.r2.cloudflarestorage.com`, or Vultr Object Storage `https://<region>.vultrobjects.com`. A secret so the account ID is masked in public logs. |

**Variables** (not secret)

| Name | Value |
|---|---|
| `STATE_BUCKET` | Bucket name, e.g. `paxoficloud-tfstate` |
| `STAGING_ADMIN_SSH_PUBLIC_KEY` | An `ssh-ed25519 …` public key (`ssh-keygen -t ed25519 -C paxoficloud-staging`) |

**State bucket:** Cloudflare R2's free tier is enough.
1. Create a private bucket.
2. Create an R2 API token with **Object Read & Write** on that bucket only.
3. Vultr Object Storage also works but is billed monthly.

## Running

GitHub → Actions → **PaxofiCloud Staging Infrastructure** → **Run workflow**.

1. Run `plan` first and read the plan in the job log.
2. Run `apply`. Each run waits for the environment approval.
3. To switch staging off and stop paying for the server, run `destroy` and type
   `destroy-staging` in the confirmation box. This removes the server, its
   firewall, the SSH key and the DNS records. Run `apply` again to bring it back.

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
