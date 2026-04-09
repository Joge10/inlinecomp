-- Starttijd-override en opmerking per individuele heat (tijdschema_ritten)
-- tijdstip_override: handmatig vastgepinde starttijd voor deze heat; herstelt cascade-berekening
-- opmerking:         vrije tekst die in intern tijdschema-print wordt getoond

ALTER TABLE tijdschema_ritten
    ADD COLUMN tijdstip_override TIME DEFAULT NULL AFTER volgorde,
    ADD COLUMN opmerking         VARCHAR(255) DEFAULT NULL AFTER tijdstip_override;
