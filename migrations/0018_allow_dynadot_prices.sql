-- Dynadot as a registrar (THREAT-MODEL-DOMAIN-PRICING.md §8, DY-2, DY-9):
-- its credential may be entered in the admin console. Snapshots already
-- accept the "sandbox" environment (0017).
ALTER TABLE provider_credential_intake DROP CHECK chk_credential_intake_provider;
ALTER TABLE provider_credential_intake ADD CONSTRAINT chk_credential_intake_provider CHECK (provider IN ('domainnameapi', 'namecheap', 'dynadot', 'hetzner', 'vultr'));
