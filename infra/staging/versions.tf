terraform {
  required_version = ">= 1.10.0"

  required_providers {
    vultr = {
      source  = "vultr/vultr"
      version = "~> 2.32"
    }
    cloudflare = {
      source  = "cloudflare/cloudflare"
      version = "~> 5.27"
    }
  }

  # Remote state lives in an S3-compatible bucket (Cloudflare R2 or Vultr Object
  # Storage). Endpoint, bucket and credentials are supplied at `tofu init` time by
  # the deploy workflow (-backend-config), never committed.
  backend "s3" {
    key                         = "paxoficloud/staging/terraform.tfstate"
    region                      = "auto"
    use_path_style              = true
    use_lockfile                = true
    skip_credentials_validation = true
    skip_region_validation      = true
    skip_requesting_account_id  = true
    skip_metadata_api_check     = true
    skip_s3_checksum            = true
  }

  # State is encrypted client-side before upload, so the bucket provider never
  # sees provider IDs or IP addresses in clear text. Unencrypted state is refused.
  encryption {
    key_provider "pbkdf2" "state" {
      passphrase = var.state_passphrase
    }
    method "aes_gcm" "state" {
      keys = key_provider.pbkdf2.state
    }
    state {
      method   = method.aes_gcm.state
      enforced = true
    }
    plan {
      method   = method.aes_gcm.state
      enforced = true
    }
  }
}

provider "vultr" {
  api_key     = var.vultr_api_key
  rate_limit  = 700
  retry_limit = 3
}

provider "cloudflare" {
  # Reads CLOUDFLARE_API_TOKEN from the environment.
}
