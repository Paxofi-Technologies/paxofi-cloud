# PaxofiCloud staging: one Vultr instance reachable only through a Cloudflare
# Tunnel (docs/security/THREAT-MODEL-DEPLOY-PIPELINE.md, option B).
#
# Exposure model:
#   - The Vultr firewall accepts NO inbound traffic (P-12). cloudflared on the
#     host dials out to Cloudflare; the origin IP is useless to an attacker.
#   - cloud-staging.<zone>      -> tunnel -> nginx on 127.0.0.1:8080
#   - cloud-staging-ssh.<zone>  -> tunnel -> sshd on 127.0.0.1:22, behind a
#     Cloudflare Access policy that admits only the CI service token (D-01).
#   - The deploy user can only run the deploy agent (P-4). The Vultr web console
#     is the break-glass path.

locals {
  name       = "paxoficloud-staging"
  tags       = ["paxoficloud", "staging", "managed-by-opentofu"]
  account_id = data.cloudflare_zone.this.account.id
  web_host   = "${var.hostname}.${var.zone_name}"
  ssh_host   = "${var.hostname}-ssh.${var.zone_name}"
  # Pinned by digest; the same image is used by CI to reach the host.
  cloudflared_image = "cloudflare/cloudflared@sha256:9b49eed8f62806d5d45ddf59ecefb5710429598ea6d3fcccd2af938f621b2b07"
}

data "cloudflare_zone" "this" {
  filter = {
    name = var.zone_name
  }
}

data "vultr_os" "ubuntu" {
  filter {
    name   = "name"
    values = [var.os_name]
  }
}

# --- Cloudflare Tunnel ------------------------------------------------------

resource "cloudflare_zero_trust_tunnel_cloudflared" "staging" {
  account_id = local.account_id
  name       = local.name
  config_src = "cloudflare"
}

resource "cloudflare_zero_trust_tunnel_cloudflared_config" "staging" {
  account_id = local.account_id
  tunnel_id  = cloudflare_zero_trust_tunnel_cloudflared.staging.id
  config = {
    ingress = [
      { hostname = local.web_host, service = "http://127.0.0.1:8080" },
      { hostname = local.ssh_host, service = "ssh://127.0.0.1:22" },
      { service = "http_status:404" },
    ]
  }
}

data "cloudflare_zero_trust_tunnel_cloudflared_token" "staging" {
  account_id = local.account_id
  tunnel_id  = cloudflare_zero_trust_tunnel_cloudflared.staging.id
}

resource "cloudflare_dns_record" "web" {
  zone_id = data.cloudflare_zone.this.zone_id
  name    = local.web_host
  type    = "CNAME"
  content = "${cloudflare_zero_trust_tunnel_cloudflared.staging.id}.cfargotunnel.com"
  proxied = true
  ttl     = 1
  comment = "PaxofiCloud staging web (managed by OpenTofu; do not edit by hand)"
}

resource "cloudflare_dns_record" "ssh" {
  zone_id = data.cloudflare_zone.this.zone_id
  name    = local.ssh_host
  type    = "CNAME"
  content = "${cloudflare_zero_trust_tunnel_cloudflared.staging.id}.cfargotunnel.com"
  proxied = true
  ttl     = 1
  comment = "PaxofiCloud staging deploy SSH, Access-protected (managed by OpenTofu)"
}

# --- Cloudflare Access: only the CI service token reaches SSH (D-01, D-12) --

resource "cloudflare_zero_trust_access_service_token" "deploy" {
  account_id = local.account_id
  name       = "${local.name}-deploy"
  duration   = "2160h" # 90 days; rotate by tainting this resource
}

resource "cloudflare_zero_trust_access_policy" "deploy" {
  account_id = local.account_id
  name       = "${local.name}-deploy-service-token"
  decision   = "non_identity"
  include = [
    { service_token = { token_id = cloudflare_zero_trust_access_service_token.deploy.id } },
  ]
}

resource "cloudflare_zero_trust_access_application" "ssh" {
  account_id           = local.account_id
  name                 = "${local.name}-ssh"
  type                 = "self_hosted"
  domain               = local.ssh_host
  session_duration     = "15m"
  app_launcher_visible = false
  policies = [
    { id = cloudflare_zero_trust_access_policy.deploy.id, precedence = 1 },
  ]
}

# --- Vultr host -------------------------------------------------------------

resource "vultr_ssh_key" "admin" {
  name    = "${local.name}-admin"
  ssh_key = var.admin_ssh_public_key
}

# No rules: Vultr drops every inbound connection to instances in this group.
resource "vultr_firewall_group" "staging" {
  description = "${local.name}: no inbound traffic (Cloudflare Tunnel only)"
}

resource "vultr_instance" "staging" {
  region            = var.region
  plan              = var.plan
  os_id             = data.vultr_os.ubuntu.id
  label             = local.name
  hostname          = local.name
  tags              = local.tags
  enable_ipv6       = true
  backups           = "disabled"
  ddos_protection   = false
  activation_email  = false
  firewall_group_id = vultr_firewall_group.staging.id
  ssh_key_ids       = [vultr_ssh_key.admin.id]
  user_data = templatefile("${path.module}/cloud-init.yaml.tftpl", {
    tunnel_token          = data.cloudflare_zero_trust_tunnel_cloudflared_token.staging.token
    cloudflared_image     = local.cloudflared_image
    deploy_ssh_public_key = var.deploy_ssh_public_key
    deploy_agent          = file("${path.module}/../../deploy/staging/host/paxoficloud-deploy")
    compose_file          = file("${path.module}/../../deploy/staging/compose.yaml")
  })

  lifecycle {
    # A changed host definition (user_data) replaces the instance; the plan shows
    # it before anyone approves the apply. OS image catalogue changes never do.
    ignore_changes = [os_id]
  }
}
