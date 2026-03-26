-- InlineComp – org_id koppeling op klassementen
ALTER TABLE klassementen
    ADD COLUMN org_id VARCHAR(36) DEFAULT NULL,
    ADD INDEX idx_kl_org (org_id);
