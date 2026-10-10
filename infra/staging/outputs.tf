output "staging_url" {
  description = "Public staging URL (served through the Cloudflare Tunnel)."
  value       = "https://${local.web_host}"
}

output "deploy_ssh_host" {
  description = "Access-protected SSH hostname used by the deploy workflow."
  value       = local.ssh_host
}

output "deploy_access_client_id" {
  description = "Cloudflare Access service-token client ID for the deploy workflow."
  value       = cloudflare_zero_trust_access_service_token.deploy.client_id
  sensitive   = true
}

output "deploy_access_client_secret" {
  description = "Cloudflare Access service-token client secret for the deploy workflow."
  value       = cloudflare_zero_trust_access_service_token.deploy.client_secret
  sensitive   = true
}

output "instance_id" {
  description = "Vultr instance ID."
  value       = vultr_instance.staging.id
}
