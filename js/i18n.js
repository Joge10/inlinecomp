// ============================================================
//  InlineComp – shared i18n helpers
//
//  Een centraal bestand met de vertaal-infrastructuur, herbruikt door
//  alle apps: public (rijder), coach, jury en uiteindelijk het admin-deel.
//
//  Inhoud:
//    - initI18n({ dict, onChange })  app-init bij DOMReady
//    - t(key, params)                vertaal sleutel met optionele {param}-substituties
//    - applyI18n(root)               doorloopt data-i18n* attributen in DOM
//    - setLang(l)                    activeer een taal ('nl','en','de','fr')
//    - toggleLang()                  cycle naar volgende beschikbare taal (legacy)
//    - getCurLang() / getLocale()    leesfuncties
//    - I18N_LANGS                    lijst van ondersteunde talen + vlag-SVG
//
//  Multi-lang setup: dict heeft een sub-object per taal-code (nl/en/de/fr).
//  NL is altijd fallback voor ontbrekende keys.
//
//  Persisteert in localStorage onder key 'ic_lang' (shared tussen apps).
//  Eerste bezoek (geen stored value): detecteer via navigator.languages,
//  match op 2-letter code tegen onze ondersteunde codes (nl/en/de/fr),
//  fallback EN als geen match. Zodra de gebruiker zelf een taal kiest via
//  de dropdown wordt die in localStorage opgeslagen — die wint dan voor-
//  taan over de browser-detectie (ook handig als ze hun device-taal
//  later wijzigen maar de InlineComp-taal willen behouden).
// ============================================================

const I18N_STORAGE_KEY = 'ic_lang';

// Ondersteunde talen + emoji-vlaggen. Op macOS/iOS/Android/Linux verschijnen
// kleurvlaggen; Windows toont fallback letter-paren (NL/GB/DE/FR) — bewust
// geaccepteerd want letterpaar = ook duidelijk genoeg.
// Volgorde = volgorde in dropdown. NL eerst (default), dan EN/DE/FR alfa-volgorde.
const I18N_LANGS = [
    { code: 'nl', naam: 'Nederlands', vlag: '🇳🇱' },
    { code: 'en', naam: 'English',    vlag: '🇬🇧' },
    { code: 'de', naam: 'Deutsch',    vlag: '🇩🇪' },
    { code: 'fr', naam: 'Français',   vlag: '🇫🇷' },
];
const I18N_CODES = I18N_LANGS.map(l => l.code);

let _i18nDict     = {};   // { nl:{...}, en:{...}, de:{...}, fr:{...} }
let _i18nOnChange = null;

// Browser-taal detectie. navigator.languages bevat de voorkeur-volgorde
// (bv. ['en-US','en','nl']); pak de eerste 2-letter code die we onder-
// steunen. Fallback EN — niet NL — zodat een buitenlandse bezoeker met
// een onbekende taal in elk geval iets leesbaars krijgt.
function _i18nDetectDeviceLang() {
    try {
        const langs = (navigator.languages && navigator.languages.length)
            ? navigator.languages
            : (navigator.language ? [navigator.language] : []);
        for (const l of langs) {
            const code = String(l).toLowerCase().slice(0, 2);
            if (I18N_CODES.includes(code)) return code;
        }
    } catch { /* navigator niet beschikbaar (oude browser/SSR) */ }
    return 'en';
}

let _i18nCurLang  = (() => {
    try {
        const stored = localStorage.getItem(I18N_STORAGE_KEY);
        if (I18N_CODES.includes(stored)) return stored;
    } catch { /* localStorage geblokkeerd (incognito + restricted) */ }
    return _i18nDetectDeviceLang();
})();

function initI18n(opts = {}) {
    _i18nDict     = opts.dict || {};
    _i18nOnChange = typeof opts.onChange === 'function' ? opts.onChange : null;
    applyI18n();
    // Custom dropdown wordt gemount via _i18nMountDropdown — als de knop
    // bestaat, transformeer 'em in een dropdown.
    _i18nMountDropdown();
}

function getCurLang() { return _i18nCurLang; }

// Activeer een taal. Onbekende codes → no-op (defensief).
function setLang(lang) {
    if (!I18N_CODES.includes(lang) || lang === _i18nCurLang) return;
    _i18nCurLang = lang;
    try { localStorage.setItem(I18N_STORAGE_KEY, _i18nCurLang); } catch {}
    applyI18n();
    if (_i18nOnChange) _i18nOnChange();
}

// Legacy: cycle door beschikbare talen. Behouden voor backwards-compat
// (oude apps die alleen .toggleLang() kennen).
function toggleLang() {
    const idx = I18N_CODES.indexOf(_i18nCurLang);
    const next = I18N_CODES[(idx + 1) % I18N_CODES.length];
    setLang(next);
}

// Vertaal een sleutel. Fallback-keten: huidige taal → EN → NL → key.
// EN is een betere fallback dan NL voor internationale gebruikers:
// een DE/FR-coach met onvertaalde key krijgt liever EN dan NL.
// Voor public (volledig 4-talig) verandert er niks — die heeft per key
// gewoon de exacte taal. {param}-placeholders worden vervangen.
function t(key, params = {}) {
    const dict   = _i18nDict[_i18nCurLang] || {};
    const enDict = _i18nDict.en || {};
    const nlDict = _i18nDict.nl || {};
    let txt = dict[key]   != null ? dict[key]
            : enDict[key] != null ? enDict[key]
            : nlDict[key] != null ? nlDict[key]
            : key;
    for (const [k, v] of Object.entries(params || {})) {
        txt = String(txt).replace(`{${k}}`, v);
    }
    return txt;
}

function applyI18n(root = document) {
    root.querySelectorAll('[data-i18n]').forEach(el => {
        el.textContent = t(el.dataset.i18n);
    });
    root.querySelectorAll('[data-i18n-html]').forEach(el => {
        el.innerHTML = t(el.dataset.i18nHtml);
    });
    root.querySelectorAll('[data-i18n-title]').forEach(el => {
        el.title = t(el.dataset.i18nTitle);
    });
    root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
        el.placeholder = t(el.dataset.i18nPlaceholder);
    });
    document.documentElement.lang = _i18nCurLang;
    _i18nUpdateDropdownLabel();
}

function getLocale() {
    return { nl: 'nl-NL', en: 'en-GB', de: 'de-DE', fr: 'fr-FR' }[_i18nCurLang] || 'nl-NL';
}

// ── Custom dropdown UI ─────────────────────────────────────────────────────
// Vervangt de #btn-lang knop door een dropdown: huidige vlag + ▼ → klik
// expandt panel met alle talen. Geen native <select> zodat de SVG-vlaggen
// netjes overal renderen.

function _i18nMountDropdown() {
    const btn = document.getElementById('btn-lang');
    if (!btn || btn.dataset.i18nMounted === '1') return;
    btn.dataset.i18nMounted = '1';
    btn.classList.add('i18n-dropdown');
    // Click → toggle panel. stopPropagation voorkomt dat de buiten-klik
    // handler 'm meteen weer dichtdoet.
    btn.addEventListener('click', e => {
        e.stopPropagation();
        _i18nToggleDropdownPanel();
    });
    // Buiten panel klikken/tikken → sluit. Gebruik mousedown (vuurt vóór
    // click, ook op touch via emulated mouseevents) zodat het paneel altijd
    // wegvalt zodra de gebruiker ergens anders aanraakt — inclusief als
    // de target zelf opnieuw een interactief element is.
    // contains() check voorkomt dat clicks ÍN het paneel het sluiten;
    // de optie-buttons hebben verder eigen click-handlers die de keuze
    // afhandelen + paneel zelf opruimen.
    const buitenDicht = e => {
        const panel = document.getElementById('i18n-dropdown-panel');
        if (panel && !panel.contains(e.target) && !btn.contains(e.target)) {
            panel.remove();
        }
    };
    document.addEventListener('mousedown', buitenDicht);
    document.addEventListener('touchstart', buitenDicht, { passive: true });
    _i18nUpdateDropdownLabel();
}

function _i18nUpdateDropdownLabel() {
    const btn = document.getElementById('btn-lang');
    if (!btn) return;
    const cur = I18N_LANGS.find(l => l.code === _i18nCurLang) || I18N_LANGS[0];
    // Geen pijltje — vlag-emoji is op zich duidelijk genoeg als "klikbaar".
    btn.innerHTML = `<span class="i18n-flag">${cur.vlag}</span>`;
    btn.title = cur.naam;
}

function _i18nToggleDropdownPanel() {
    const bestaand = document.getElementById('i18n-dropdown-panel');
    if (bestaand) { bestaand.remove(); return; }
    const btn = document.getElementById('btn-lang');
    if (!btn) return;
    const panel = document.createElement('div');
    panel.id = 'i18n-dropdown-panel';
    panel.className = 'i18n-dropdown-panel';
    // Compact horizontaal: alleen vlaggen, geen tekstnamen. Naam blijft
    // beschikbaar via title-attribuut (hover-tooltip).
    panel.innerHTML = I18N_LANGS.map(l => `
        <button type="button" class="i18n-dropdown-opt ${l.code === _i18nCurLang ? 'is-active' : ''}"
                data-lang="${l.code}" title="${l.naam}" aria-label="${l.naam}">
            <span class="i18n-flag">${l.vlag}</span>
        </button>
    `).join('');
    // Positioneer onder de knop, rechts uitgelijnd. Daarna corrigeer als
    // het paneel buiten het scherm valt (links < 4px of rechts > vw-4px).
    const r = btn.getBoundingClientRect();
    panel.style.position = 'fixed';
    panel.style.top   = (r.bottom + 4) + 'px';
    panel.style.right = Math.max(4, window.innerWidth - r.right) + 'px';
    panel.style.zIndex = '10000';
    document.body.appendChild(panel);
    // Na append: meet werkelijke breedte en clamp binnen viewport.
    const panelR = panel.getBoundingClientRect();
    if (panelR.left < 4) {
        panel.style.right = 'auto';
        panel.style.left  = '4px';
    }
    panel.querySelectorAll('.i18n-dropdown-opt').forEach(b => {
        b.addEventListener('click', e => {
            e.stopPropagation();
            // EERST paneel weg, DAN setLang. setLang triggert applyI18n +
            // onChange (in coach: _rerenderCoach) wat de DOM kan
            // vernieuwen. Als het paneel daar nog in staat raakt 'ie
            // verwees of blijft visueel hangen. Andersom is altijd veilig.
            panel.remove();
            setLang(b.dataset.lang);
        });
    });
}
