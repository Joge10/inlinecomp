-- Migratie: merge_label kolom toevoegen aan distance_combinations
-- Gebruikers kunnen hiermee een korte weergavenaam geven aan een samengevoegde groep.

ALTER TABLE distance_combinations
    ADD COLUMN merge_label VARCHAR(80) DEFAULT NULL;
