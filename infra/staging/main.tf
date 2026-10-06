# PaxofiCloud staging: one Vultr instance behind Cloudflare.
#
# Exposure model (see README.md):
#   - HTTPS (443) is reachable only from Cloudflare's published edge ranges, so
#     the WAF, rate limiting and TLS policy in Cloudflare cannot be bypassed by
#     hitting the origin IP directly.
#   - SSH is closed unless admin_ssh_cidrs is set; key-only, root login off.
#   - Nothing else is open. The Vultr web console is the break-glass path.

locals {
  name = "paxoficloud-staging"
  tags = ["paxoficloud", "staging", "managed-by-opentofu"]

  edge_v4 = { for c in data.cloudflare_ip_ranges.edge.ipv4_cidrs : "v4 ${c}" => { type = "v4", cidr = c } }
  edge_v6 = { for c in data.cloudflare_ip_ranges.edge.ipv6_cidrs : "v6 ${c}" => { type = "v6", cidr = c } }
  ssh_v4  = { for c in var.admin_ssh_cidrs : "ssh ${c}" => { type = "v4", cidr = c } }
}

data "cloudflare_zone" "this" {
  filter = {
    name = var.zone_name
  }
}

data "cloudflare_ip_ranges" "edge" {}

data "vultr_os" "ubuntu" {
  filter {
    name   = "name"
    values = [var.os_name]
  }
}

resource "vultr_ssh_key" "admin" {
  name    = "${local.name}-admin"
  ssh_key = var.admin_ssh_public_key
}

resource "vultr_firewall_group" "staging" {
  description = "${local.name}: HTTPS from Cloudflare only; SSH from admin CIDRs only"
}

resource "vultr_firewall_rule" "https_from_cloudflare" {
  for_each = merge(local.edge_v4, local.edge_v6)

  firewall_group_id = vultr_firewall_group.staging.id
  protocol          = "tcp"
  ip_type           = each.value.type
  subnet            = split("/", each.value.cidr)[0]
  subnet_size       = tonumber(split("/", each.value.cidr)[1])
  port              = "443"
  notes             = "cloudflare-edge"
}

resource "vultr_firewall_rule" "ssh_from_admin" {
  for_each = local.ssh_v4

  firewall_group_id = vultr_firewall_group.staging.id
  protocol          = "tcp"
  ip_type           = each.value.type
  subnet            = split("/", each.value.cidr)[0]
  subnet_size       = tonumber(split("/", each.value.cidr)[1])
  port              = "22"
  notes             = "admin-ssh"
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
  user_data         = file("${path.module}/cloud-init.yaml")

  lifecycle {
    # Re-provisioning staging must be a deliberate replace, not a side effect of
    # an image catalogue change.
    ignore_changes = [os_id, user_data]
  }
}

resource "cloudflare_dns_record" "staging_v4" {
  zone_id = data.cloudflare_zone.this.zone_id
  name    = "${var.hostname}.${var.zone_name}"
  type    = "A"
  content = vultr_instance.staging.main_ip
  proxied = true
  ttl     = 1
  comment = "PaxofiCloud staging (managed by OpenTofu; do not edit by hand)"
}

resource "cloudflare_dns_record" "staging_v6" {
  zone_id = data.cloudflare_zone.this.zone_id
  name    = "${var.hostname}.${var.zone_name}"
  type    = "AAAA"
  content = vultr_instance.staging.v6_main_ip
  proxied = true
  ttl     = 1
  comment = "PaxofiCloud staging (managed by OpenTofu; do not edit by hand)"
}
