# Database-migraties

Deze map bevat éénmalige ALTER-scripts die op bestaande installaties gedraaid moeten worden om het schema op peil te brengen. Voor **verse installaties** is de map `db/` leidend — daar staan per tabel de volledige `CREATE TABLE`-statements met alle kolommen zoals ze nu horen te zijn.

## Conventies

- Bestandsnaam: `YYYY-MM-DD_<korte-beschrijving>.sql`
- Eén wijziging per bestand (tenzij logisch samenhangend)
- Bovenaan een commentaarblok met: wat verandert er, waarom, en wanneer het gedraaid moet worden.
- Idempotent waar mogelijk (fouten negeren als de wijziging al is toegepast).

## Historie

| Datum | Bestand | Omschrijving |
|---|---|---|
| 2026-04-21 | `2026-04-21_distances_add_race_type.sql` | `race_type` ENUM-kolom toevoegen aan `distances` (sprint/inline/puntenkoers/afvalkoers) |
| 2026-04-24 | `2026-04-24_organisaties_add_sportity_kanaal.sql` | `sportity_kanaal` kolom op `organisaties` voor per-regio Sportity-kanaalnaam |
| 2026-04-24 | `2026-04-24_klassement_posities_categorie_verruimen.sql` | `klassement_posities.categorie` van VARCHAR(20) naar VARCHAR(100) voor langere sectie-labels uit NK-tussenstand-PDFs |
