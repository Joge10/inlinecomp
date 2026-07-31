-- 2026-07-31 – login_logs: jury-entries krijgen bron='jury'
--
-- Voorheen logde de jury-app met bron='staff' (de default) en werd jury alleen
-- herkend aan de actie-prefix 'jury-'/'scheids-'. Nu maken we `bron` de primaire
-- as: jury krijgt bron='jury'. Zo lopen toekomstige jury-rollen (bv. starter-)
-- vanzelf mee onder het jury-filter zonder dat we de filter hoeven aanpassen,
-- en worden de filters staf/jury/coach alle drie puur op `bron`.
--
-- Deze migratie zet bestaande jury-rijen (die nog bron='staff' hebben) om.
-- Dekt alle jury-app-acties: jury-login, jury-login-fail(-noaccess),
-- jury-logout en jury-rol-* (rol-keuzes), plus historische scheids-* rijen.
-- Bewust ZONDER koppelteken in het patroon ('jury%' i.p.v. 'jury-%'), zodat
-- ook afgekapte acties (de oude VARCHAR(20) kapte bv. 'jury-rol-area_of_call'
-- af) en eventuele toekomstige varianten meelopen.
-- Idempotent: draait veilig meermaals (raakt alleen rijen die nog 'staff' zijn).

UPDATE `login_logs`
   SET `bron` = 'jury'
 WHERE `bron` = 'staff'
   AND (`actie` LIKE 'jury%' OR `actie` LIKE 'scheids%');
