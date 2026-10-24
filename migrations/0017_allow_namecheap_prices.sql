-- Namecheap as registrar #2 (THREAT-MODEL-DOMAIN-PRICING.md NC-2, NC-9):
-- its credential may be entered in the admin console, and its price
-- snapshots record the "sandbox" environment when the sandbox API is used
-- (never in production).
ALTER TABLE provider_credential_intake DROP CHECK chk_credential_intake_provider;
ALTER TABLE provider_credential_intake ADD CONSTRAINT chk_credential_intake_provider CHECK (provider IN ('domainnameapi', 'namecheap', 'hetzner', 'vultr'));
ALTER TABLE domain_cost_snapshots DROP CHECK chk_domain_cost_snapshots_environment;
ALTER TABLE domain_cost_snapshots ADD CONSTRAINT chk_domain_cost_snapshots_environment CHECK (environment IN ('live', 'ote', 'sandbox'));
