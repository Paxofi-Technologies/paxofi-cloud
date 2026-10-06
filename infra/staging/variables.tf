variable "state_passphrase" {
  description = "Passphrase for client-side state encryption (TF_VAR_state_passphrase). At least 16 characters."
  type        = string
  sensitive   = true

  validation {
    condition     = length(var.state_passphrase) >= 16
    error_message = "state_passphrase must be at least 16 characters."
  }
}

variable "vultr_api_key" {
  description = "Vultr API key of the least-privilege staging IaC user (TF_VAR_vultr_api_key, from the GitHub Environment secret)."
  type        = string
  sensitive   = true
}

variable "zone_name" {
  description = "Cloudflare zone that holds the staging hostname."
  type        = string
  default     = "paxofi.com"
}

variable "hostname" {
  description = "Staging hostname label inside the zone. Must be one level deep so Cloudflare's free Universal SSL covers it."
  type        = string
  default     = "cloud-staging"

  validation {
    condition     = can(regex("^[a-z0-9-]+$", var.hostname))
    error_message = "hostname must be a single DNS label (no dots); deeper names are not covered by Universal SSL."
  }
}

variable "region" {
  description = "Vultr region ID."
  type        = string
  default     = "jnb"
}

variable "plan" {
  description = "Vultr plan ID for the staging server."
  type        = string
  default     = "vc2-2c-4gb"
}

variable "os_name" {
  description = "Exact Vultr OS name to install."
  type        = string
  default     = "Ubuntu 24.04 LTS x64"
}

variable "admin_ssh_public_key" {
  description = "Public SSH key for the deploy user (TF_VAR_admin_ssh_public_key). Public keys are not secrets, but are kept out of the repo."
  type        = string

  validation {
    condition     = can(regex("^ssh-ed25519 [A-Za-z0-9+/=]+( .*)?$", var.admin_ssh_public_key))
    error_message = "admin_ssh_public_key must be an ssh-ed25519 public key."
  }
}

variable "admin_ssh_cidrs" {
  description = "IPv4 CIDRs allowed to reach SSH. Empty (default) means SSH is closed and the Vultr console is the break-glass path."
  type        = list(string)
  default     = []

  validation {
    condition     = alltrue([for c in var.admin_ssh_cidrs : can(cidrhost(c, 0)) && !startswith(c, "0.0.0.0/")])
    error_message = "admin_ssh_cidrs must be valid IPv4 CIDRs and must not open SSH to the whole internet."
  }
}
