-- Domain extensions: PROMO label (DX-13), shown in red like HOT.
ALTER TABLE domain_extension_settings
    DROP CHECK chk_domain_extension_settings_label,
    ADD CONSTRAINT chk_domain_extension_settings_label CHECK (label IN ('none', 'hot', 'promo', 'sale', 'new'));
