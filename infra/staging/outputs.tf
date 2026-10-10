output "staging_url" {
  description = "Public staging URL (served through Cloudflare)."
  value       = "https://${var.hostname}.${var.zone_name}"
}

output "instance_id" {
  description = "Vultr instance ID."
  value       = vultr_instance.staging.id
}

output "firewall_rule_count" {
  description = "Number of inbound rules on the staging firewall group."
  value       = length(vultr_firewall_rule.https_from_cloudflare) + length(vultr_firewall_rule.ssh_from_admin)
}
