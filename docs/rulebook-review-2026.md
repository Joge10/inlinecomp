# World Skate Speed Rulebook 2026 — kritische review

Doorlezing op zoek naar onduidelijkheden, overlap, contradictie, ontbrekende edge cases en lokale-versus-internationale gaten. Per item: **artikel + quote + issue + suggestie**.

> Bron: Speed_Rulebook_2026 (update oktober 2025), 5467 regels in tekst-extract.
> Reviewer: technische lezing voor wedstrijd-software, geen jurybekwaamheidsclaim.

---

## 1. Inconsistenties & contradicties

### 1.1 False start (FS) — Art. 171.1 vs Art. 174.1
Art. 171.1.a: *"two (2) consecutive false starts (300m)"* → DQ-TF.
Art. 171.1.b: *"two (2) false starts (FS)"* → DQ-TF.
Art. 174.1: *"Only one (1) false start (FS) is allowed per race (except 300m TT)."*

**Issue**: 171.1.a heeft "(300m)" tussen haakjes, 171.1.b niet. Wat is het verschil? Geldt "consecutive" alleen voor 300m TT en mogen 2 niet-opeenvolgende FS bij andere races? Art. 174.1 zegt "1 per race (except 300m TT)" → dus bij 300m TT mogen er 2? En tegelijk zegt 171.1.b "2 FS" zonder restrictie? Onduidelijk.

**Suggestie**: één regel formuleren: "1 FS per race; uitzondering 300m TT = 2 toegestaan; daarna DQ-TF".

### 1.2 Type van race — 100m sprint = "Long Distance"? Art. 109
Tabel zet 100m sprint, 500m+D Sprint, 1 Lap Sprint, 1.000m Sprint allemaal onder **"Long Distance"**.

**Issue**: dat is contra-intuïtief en botst met Art. 144.4 dat de "ex-aequo bij DNS/DNF over heats"-regel beperkt tot **"short distance races"**. Geldt die regel dan niet voor 100m sprint? Vrijwel zeker een classificatie-vergissing in de definitie-tabel.

**Suggestie**: tabel herzien — "Short Distance" zou minimaal alle Sprint-races moeten omvatten.

### 1.3 DNS — wel of niet meetellen? Art. 173 vs Art. 144.4
Art. 173.7: *"(DNS) did not start."* — meer staat er niet over ranking.
Art. 144.4: *"In short distance races, DNS (except the first round) and DNF (from different heats) skaters shall be ranked ex-aequo."*

**Issue**: Art. 173 (officiële remarks) noemt DNS expliciet zonder verdere ranking-uitleg, terwijl DQ-SF/DQ-DF in datzelfde artikel WEL "general ranking: no points" krijgen. Voor general ranking (multi-distance event) is voor DNS niets geregeld. De jury moet zelf bedenken hoe.

**Suggestie**: in 173.7-9 expliciet maken: "in general ranking, [wel/geen] points are received".

---

## 2. Vage formuleringen

### 2.1 "Takes his place" — Art. 142
*"When skaters are disqualified for sports or disciplinary faults (DQ-SF / DQ-DF), they are not ranked, and the following skater takes his place."*

**Issue**: "takes his place" = de rangs opschuiven? Bv. plek 3 = DQ-SF → wordt plek 4 nieuwe plek 3, plek 5 wordt 4, etc.? Of behoudt iedereen z'n nominale plek en valt #3 alleen weg uit de uitslag? Bepaalt sterk hoe de medailles toegekend worden bij DQ in een finale.

**Suggestie**: expliciet voorbeeld toevoegen ("if the 3rd-place finisher is DQ-SF, the original 4th becomes 3rd, and so on").

### 2.2 RR-tijdtoekenning — Art. 170.4
*"the skater in fault takes the time of the affected skater. The time obtained by the skater in fault will be assigned to the affected skater(s)."*

**Issue**: dit is wederzijds — A neemt tijd van B, B krijgt tijd van A. Bij meerdere slachtoffers: *"they will be ranked with the same time and in the order in which they arrived"* — krijgen ze allemaal de tijd van A? Dat zou betekenen dat een snelle hindering een hele groep onterecht voordeel geeft. Bedoeling onduidelijk.

**Suggestie**: voorbeeld met cijfers in de tekst.

### 2.3 RR vs DQ-SF — Art. 165.b
*"the sanction Reduce in Rank (RR) can be used. This is possible only if the skater in fault did not push, cut, cause a fall, affect or benefit the placement of other skater(s)."*

**Issue**: als de overtreder wél "benefit the placement of other skater(s)" → RR is uitgesloten, maar het reglement zegt niet expliciet wat dán: vermoedelijk DQ-SF (Art. 171.2.f *"when it is not possible to apply a Reduce in Rank sanction (RR)"*), maar dat is een verspreide kruisverwijzing.

**Suggestie**: in 165.b één zin toevoegen: "If RR cannot be applied (per Art. 171.2.f), the skater is disqualified (DQ-SF)."

### 2.4 Sports fault warnings in sprints — Art. 169.4
*"Warning for sports fault (SF) does not exist for sprint races."*

**Issue**: betekent dit dat in sprint-races geen W1/W2 voor SF wordt uitgedeeld → directe DQ-SF? En welke "sprint races" precies? 100m, 500m+D, 1Lap, 1000m, ÉN 200m DTT? Niet gespecificeerd.

**Suggestie**: list maken van welke races onder deze regel vallen.

---

## 3. Overlap & duplicatie

### 3.1 DNS-definitie staat 2× — Art. 125.1 + Art. 126.6
Art. 125.1: *"If skaters do not answer the call area judge after being called twice, at one-minute intervals … they are marked as Did Not Start (DNS). A registered skater not showing up for a race -Did Not Start (DNS)- during the first round, will not be allowed to take part in the following race … (DNS2)."*

Art. 126.6: *"If there is not any call area, the skater does not answer the Starters after being called twice on the start line; at one-minute intervals … they are marked as Did Not Start (DNS). A registered skater not showing up for a race -Did Not Start (DNS)- during the first round, will not be allowed to take part in the following race … (DNS2)."*

**Issue**: bijna woordelijk dezelfde regel — eenmaal voor "call area aanwezig", eenmaal voor "geen call area". Onnodige duplicatie; risico op divergentie bij toekomstige wijzigingen. Wat als ER een call area IS maar de starter ook nog roept? Dubbele DNS-check?

**Suggestie**: één paragraaf met twee sub-regels (a/b) voor de twee scenario's.

### 3.2 "Particular situation" duplicaat — Art. 125.2 + Art. 126.7
Letterlijk identieke tekst:
*"In case a skater is unable to be present in a race, first round, due to a particular situation, the delegate shall report it to the Technical Commission, for them to evaluate the situation and decide whether to apply the DNS2 or not."*

**Suggestie**: één keer noemen.

### 3.3 Sports fault definities verspreid — Art. 163, 164, 165, 171.2
- 163 = algemene definitie + 5 specifieke faults
- 164 = "specific sports faults" (grabbing, hipping, jamming, …)
- 165 = "sport fault — trajectory and obstructions" (blocking, elbowing, …)
- 171.2 = lijst van DQ-SF gronden (12 items, deels overlap met 163-165)

**Issue**: een rijder kan 4 artikelen openslaan voor één begrip. Veel doublures (bv. 163.1 *"Get out voluntarily the racecourse"* en 171.2.c *"when a skater gets out voluntarily of the race course"*).

**Suggestie**: consolideren in één lijst, met DQ-SF als gevolg expliciet per regel.

### 3.4 "Eliminate during first 1000m" — Art. 118.1, 119.1, 204.2
Drie artikelen herhalen *"There will not be eliminations during the first one thousand (1.000) meters of the race"* (in iets verschillende formuleringen, en 119.1 met uitzondering "except for the 5.000m Points race").

**Suggestie**: één algemene regel + uitzonderingen.

---

## 4. Edge cases zonder regel

### 4.1 DNF binnen één heat — Art. 144.4 onvolledig
*"DNS (except the first round) and DNF (from different heats) skaters shall be ranked ex-aequo."*

**Issue**: en wat met meerdere DNF in dezelfde heat? Niet ex-aequo (per de "from different heats"-clausule), dus dan wel? Hoe ranken dan onderling?

**Suggestie**: clausule toevoegen: "DNF skaters within the same heat are ranked according to the order they left the race" of "ex-aequo".

### 4.2 Ex-aequo zonder photo-finish — Art. 144.1
*"When a group of skaters crosses the finish line all together, and thus it is not possible to determine their exact finish order, all these skaters involved will be awarded the same placement position and will be listed in the nations' ranking order."*

**Issue**: wat als noch foto-finish noch video beschikbaar is? Wie bepaalt dan dat het "all together" is? Alleen op basis van judge-oog?

**Suggestie**: minimum-bewijslast specificeren (bv. "in absence of photo-finish, the Chief Judge decision is final").

### 4.3 Race kan niet voltooid worden door technische storing — geen artikel
Art. 132-136 behandelen neutralisatie, stop, resume, restart, cancellation. Maar wat als de finale onvoltooibaar blijkt nadat 'ie is gecancelled? Worden de medailles toegekend op basis van semi-finale-tijden? Niet geregeld.

**Suggestie**: paragraaf "if a final cannot be re-run, the result of the latest completed round determines the medals".

### 4.4 200m DTT met oneven aantal rijders in finale-heat — Art. 196
Art. 196.1: *"twelve (12) best times are qualified for the final"*. 12 rijders in 6 heats van 2 — perfect. Maar wat als 196.2 *"insufficient skaters → 8 best times"* + er zijn er 9 (door ex-aequo)? 9 rijders, even aantal benodigd.

**Suggestie**: ex-aequo regel voor finale-toelating expliciet maken.

### 4.5 Heat composition als "Ranking N-1" niet bestaat — Art. 195
Art. 195: *"according to the ranking of the nations of the previous competition of the same type"*. Art. 193: nations zonder vorige WSC krijgen alfabetische volgorde. Maar voor SKATERS specifiek (in plaats van nations) is dit niet geregeld — bv. eerste editie van een nieuwe wedstrijd-categorie.

---

## 5. Race-type specifieke onduidelijkheden

### 5.1 1.000m Sprint kwalificatietabel mogelijk fout — Art. 116
Tabel toont voor 8 deelnemers: *"4 x 6-8, 16 best times"*. 8 rijders kunnen onmogelijk 16 doorlaten. Vermoedelijk PDF-uitlees-artefact (de "16" hoort waarschijnlijk bij een andere rij). Verifiëren in originele PDF aan te raden.

**Issue**: ambiguïteit doordat tabelopmaak in PDF afwijkt van tekstuitlezing.

### 5.2 Loser final voor lange afstand — Art. 198.2
*"There will be no loser's final. Unqualified skaters are ranked according to the result (place) obtained in the qualification heat."*

**Issue**: als 4e in heat 1 vs 4e in heat 2 — ex-aequo? Of cross-heat tijd-tiebreak (lange afstand → tijden niet vergelijkbaar tussen heats)? Niet expliciet.

**Suggestie**: expliciet "ex-aequo same place" of "tiebreak by … if comparable".

### 5.3 Eliminatie-getallen — Art. 199.2
*"Total 31 eliminations + 5 skaters at the end. Seven (7) double eliminations."* Bij 36 starters: 31 eliminees + 5 finishers = 36 ✓. 7 dubbele = 14 mensen + 17 enkele = 17 mensen → 31 totaal ✓. Maar de tekst noemt "31 eliminations" voor mensen én "7 double eliminations" voor events — dezelfde term met twee betekenissen.

**Suggestie**: schrijf "31 skaters eliminated, in 24 elimination events (7 of which were double)".

### 5.4 Points race ex-aequo bij gelijke punten — Art. 119.6
*"If there is a tie in points among two (2) or more skaters, it will be decided by who was the first of them at the finish line in the last lap."*

**Issue**: en bij gelijke positie op finish line? Cascadeert niet door naar volgende criterium. Geen ultieme tiebreak.

### 5.5 Points-Elimination interactie — Art. 120 + 200
Art. 120 zegt simpel "combinatie eliminate + punten". Art. 200 (track 10km PE) specificeert. Maar wat met road points-elimination? Geen apart artikel; alleen Art. 120 generic.

### 5.6 500m+D race-lengte is baan-afhankelijk — Art. 115.b
Art. 115.b: *"On track, the distance of race is 2.5 laps (500m) plus the Distance resulting in the middle of the straight, thus the start line will be in the middle of the straight."*

**Issue**: de "+D" is geometrisch bepaald: het is de afstand die nodig is om de startlijn in het midden van het rechte stuk te krijgen. Op een 200m baan met perimeter `2S + 2πR = 200` geldt `D = S/2 = 50 − πR/2`. **D hangt direct af van de bocht-straal R**, en het rulebook stelt GEEN expliciete minimum/maximum waarden voor R.

Het rulebook (Art. 88, 90, 91) geeft alleen:
- Baanlengte 175–200 m, tolerantie ±5 cm (Art. 90.1)
- Breedte 5.5–6 m (Art. 91)
- Symmetrische bochten met gelijke straal (Art. 87.6)
- Banking ≥ 100 cm langs buitenrand bij 200m baan (Art. 92.3)

Praktische bouw-range op een 200m baan: R ≈ 13–22 m (kleinere R = onuitvoerbare banking, grotere R = bijna geen rechte stukken). Dat resulteert in:

| R (m) | S = 100−πR | D = S/2 | Race-totaal |
|---|---|---|---|
| 13  | 59.16 m | 29.58 m | 529.58 m |
| 15  | 52.88 m | 26.44 m | 526.44 m |
| 18  | 43.45 m | 21.72 m | 521.72 m |
| 22  | 30.88 m | 15.44 m | 515.44 m |

**Worst-case spread** binnen reglementair acceptabele bouw: **~14 m verschil** in race-totaal tussen krapste en ruimste baan. Bij sprint-snelheid (~12-13 m/s gemiddeld) komt dat neer op **~1.0 à 1.1 seconde** verschil — significant voor sprint-records, en groter dan de marges waarbinnen records normaal gesproken sneuvelen.

**Implicaties**:
1. **Records-vergelijking is niet apples-to-apples**: een 500m+D-tijd in Lagos (R onbekend, mogelijk ruim) is niet 1-op-1 vergelijkbaar met dezelfde race op Heerde of Geisingen.
2. Het rulebook normaliseert NIET voor baan-geometrie bij record-erkenning of multi-wedstrijd-ranking.
3. World Skate baan-certificering (Art. 87.7, "may be certified") publiceert R niet als verplicht meta-veld — er is geen openbaar baan-register met R per locatie.
4. **Wereldrecord-procedure (Art. 61-65) bevestigt dit gat expliciet**: Art. 63 stelt slechts "all items of this Rulebook respected" + "electronic timekeeping". GEEN clausule over baan-certificering vooraf, GEEN R-publicatie verplicht in de documentatie (Art. 64.3.a vraagt enkel "plan of the competition course… indicating the course length, starting point, finish line and the exact number of laps") — bocht-straal wordt niet als verplicht gegeven gevraagd. Dus een WR op 500m+D in een baan met R=22m kan formeel gelden tegenover een WR-poging in R=15m-baan, ondanks ~10m verschil in feitelijke race-afstand.

**Suggestie**:
- Optie A: bij baan-certificering verplichten dat R wordt gepubliceerd en records voor 500m+D voorzien van baan-tag.
- Optie B: een **harmonisatie-tabel** met race-totalen per R-bracket (bv. R 13-16 / 16-19 / 19-22) zodat de operator weet of records inderdaad vergelijkbaar zijn.
- Optie C: minimum/maximum R expliciet stipuleren in Art. 90/91 zodat de race-spread beperkt blijft tot bv. ±3 m (≈ ±0.25 s).

---

## 6. Definities & terminologie

### 6.1 "Sprint race" inconsistent
Soms = alle short-distance races (Art. 113). Soms = alleen 100m (Art. 130). Soms exclusief 1000m (Art. 129 titel: "except 1.000m"). Verwarrend bij sancties zoals Art. 169.4 "warning SF doesn't exist for sprint races" — welke "sprints"?

### 6.2 "Round" / "Heat" / "Series" door elkaar
- Art. 113.1: *"a sprint race is organized as a short distance race with a certain number of rounds"*
- Art. 114.7 tabel: kolom-headers gebruiken "1/8 final", "Quarter Finals" etc. zonder mapping naar generieke "round".
- Art. 144.3: *"disqualified skaters for technical fault (DQ-TF) in the same round"*
- Art. 195: *"ranking of the nations of the previous competition of the same type"* — "same type" = round type? distance type?

**Suggestie**: glossary met definities round / heat / qualification heat / final / etc.

### 6.3 "Qualification heat" vs "Series"
Beide termen lijken hetzelfde te betekenen (eerste ronde) maar worden door elkaar gebruikt. KNSB praat altijd over "series" ("Serie"). World Skate gebruikt overwegend "qualification heats", maar Art. 140.2.a noemt voor 100m sprint óók "Series" als ronde-type.

---

## 7. KNSB-vs-World Skate gaten

Items waar het Nederlandse circuit iets doet wat World Skate niet (expliciet) regelt:

### 7.1 Runner-up race
KNSB heeft een aparte "runner-up finale" voor afvallers na de eerste ronde, met eigen plek-blok in het klassement. **World Skate kent dit fenomeen niet** — Art. 198.2 zegt simpel "no loser's final".

### 7.2 B-finales voor short-distance sprint > 100m
Art. 114.13 specificeert Final A + Final B voor 100m. Voor 500m+D / 1Lap / 1000m: tabellen tonen wel kwart→halve→finale, maar het concept "B-finale" wordt niet expliciet beschreven. KNSB hanteert wel meerdere B-finales (bv. B1, B2).

### 7.3 Cross-wedstrijd serie-klassement
Art. 57 (international ranking) gaat over medailles, niet over een KNSB-stijl serie waarbij meerdere wedstrijden punten optellen + streepresultaten. Art. 59.3.a ("3 best results") is het dichtste maar zeer beperkt.

### 7.4 Wedstrijd-aftrek (streep) regels
Geen World Skate equivalent. KNSB-conventie volledig lokaal.

### 7.5 Categorie-systeem
Art. 51-52 noemt "Junior", "Senior" voor World Champs. Geen leeftijds-grenzen, geen Pupillen/Kadetten/Junioren-systeem zoals KNSB.

---

## 8. Aanbevelingen voor de jury / KNSB-implementatie

Concrete keuzes die KNSB moet vastleggen waar World Skate stilzwijgt:

1. **DNS in multi-distance overall klassement**: hoe meetellen? (Mijn implementatie: groepering "aantal afstanden gereden", DNS = niet uitgesloten, DQ-SF/DQ-DF wel.)
2. **Runner-up plek-toekenning**: World Skate kent 't niet, dus de KNSB-regel ("plek 17-20 als heat 1 van runner-up, plek 21+ als heat 2") is volledig lokaal en mag eigen nuances hebben (per-heat ranking vs gepoolde tijd-ranking).
3. **B-finales > 100m**: aantal B-heats per cat-config, distributie van rest-rijders — World Skate biedt geen template.
4. **Streepresultaten + DNS**: World Skate-spirit ("doet niet mee = geen punten, geen straf") komt overeen met "missende wedstrijd vult streep automatisch op".
5. **Sport Fault (DQ-SF) klassering**: Art. 142 + 173.5 zegn klip en klaar "not ranked, no points" — oudere KNSB-conventies die DQ-SF "gedeeld laatste" zouden geven, kloppen niet met het reglement.
6. **False start administratie**: kan een rijder met FS1 nog doorgaan? Art. 174.1 "1 per race" suggereert ja maar Art. 173.2 noemt FS1 als sanctie-vermelding. Beleid vastleggen.
7. **DNF binnen één heat**: Art. 144.4 dekt 't niet — KNSB-keuze: ex-aequo of volgorde van uitvallen.

---

## 9. Schoonheidsfoutjes (geen functioneel issue)

- Inconsistente nummering: artikelen 51-52 staan in TOC zonder paginanummers, artikel 51 lijkt overgeslagen.
- Inconsistente citeerstijl: soms "Art. 142", soms "Article 142", soms "Art 142".
- Sommige zinnen lopen over twee artikelen heen (bv. Art. 174.2 begint midden in een zin van 174.1 en eindigt in 174.3).
- Tabellen in de PDF worden door tekst-extract niet altijd correct uitgelezen (zie 5.1) — voor implementatie altijd terugvallen op de PDF, niet op tekst-uittreksel.

---

## Slot

Dit document is een leeswijzer voor wie de software wil aanlijnen met het reglement of regelement-discussies wil voorbereiden. Niet alles wat ik "onduidelijk" noem is fout — vaak is het bedoeld als "Chief Judge oordeelt". Maar voor automatisering moet je elke gap invullen, en dan is 't goed te weten wáár de gaten zitten.

Heb je vragen of wil je verdiepen op een specifiek artikel, dan kun je 'm pakken vanuit dit overzicht.
