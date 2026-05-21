// ============================================================
//  InlineComp – shared i18n helpers
//
//  Een centraal bestand met de vertaal-infrastructuur, herbruikt door
//  alle apps: public (rijder), coach (toekomst), jury (toekomst) en
//  uiteindelijk het admin-deel.
//
//  Inhoud:
//    - initI18n({ dict, onChange })  app-init bij DOMReady
//    - t(key, params)                vertaal sleutel met optionele {param}-substituties
//    - applyI18n(root)               doorloopt data-i18n* attributen in DOM
//    - toggleLang() / setLang(l)     wissel taal (NL/EN)
//    - getCurLang() / getLocale()    leesfuncties
//
//  Gebruik per app (vereenvoudigd voorbeeld; geen HTML-tags hier
//  vanwege HTML-parser-conflicten als deze comment via readfile in
//  een script-blok komt):
//
//    1) Laad dit bestand inline via PHP readfile in een SCRIPT-blok.
//    2) Definieer je app-T-object: const T_APP = { nl:{}, en:{} }
//    3) Bij DOMReady: initI18n({ dict: T_APP, onChange: myRerender })
//    4) Zet ergens in je header een knop met id "btn-lang" — vlag-icoon
//       wordt automatisch ingevuld + toggle-handler gekoppeld.
//
//  Marker-attributen voor static HTML (worden bij applyI18n vervangen):
//      data-i18n             -> element.textContent
//      data-i18n-html        -> element.innerHTML
//      data-i18n-title       -> element.title
//      data-i18n-placeholder -> element.placeholder
//
//  In dynamische JS-templates: gebruik t('key') in je template-literals.
//
//  Persisteert in localStorage onder key 'ic_lang' (shared tussen apps).
//  Default-taal = nl als nooit gekozen.
// ============================================================

const I18N_STORAGE_KEY = 'ic_lang';

let _i18nDict     = { nl: {}, en: {} };
let _i18nOnChange = null;
let _i18nCurLang  = (() => {
    try { return localStorage.getItem(I18N_STORAGE_KEY) || 'nl'; }
    catch { return 'nl'; }
})();

// Initialiseer het i18n-systeem voor deze app.
//   opts.dict:     { nl: {key: 'NL-tekst', ...}, en: {key: 'EN-text', ...} }
//   opts.onChange: optionele callback die wordt aangeroepen NA elke taal-
//                  wissel (na applyI18n). Gebruik dit om dynamische content
//                  opnieuw te renderen (bv. herrendering van een actieve
//                  lijst, dropdown, of widget).
function initI18n(opts = {}) {
    _i18nDict     = opts.dict || { nl: {}, en: {} };
    _i18nOnChange = typeof opts.onChange === 'function' ? opts.onChange : null;
    applyI18n();
    document.getElementById('btn-lang')?.addEventListener('click', toggleLang);
}

function getCurLang() { return _i18nCurLang; }

function setLang(lang) {
    const nieuw = (lang === 'en') ? 'en' : 'nl';
    if (nieuw === _i18nCurLang) return;
    _i18nCurLang = nieuw;
    try { localStorage.setItem(I18N_STORAGE_KEY, _i18nCurLang); } catch {}
    applyI18n();
    if (_i18nOnChange) _i18nOnChange();
}

function toggleLang() {
    setLang(_i18nCurLang === 'nl' ? 'en' : 'nl');
}

// Vertaal een sleutel. NL is fallback als de actuele taal de key niet kent
// (handig tijdens uitrol: nieuwe keys werken meteen in NL, EN volgt later).
// {param}-placeholders worden vervangen met values uit `params`.
function t(key, params = {}) {
    const dict = _i18nDict[_i18nCurLang] || _i18nDict.nl || {};
    let txt = dict[key] != null ? dict[key]
            : (_i18nDict.nl && _i18nDict.nl[key] != null) ? _i18nDict.nl[key]
            : key;
    for (const [k, v] of Object.entries(params || {})) {
        txt = txt.replace(`{${k}}`, v);
    }
    return txt;
}

// Doorloop DOM (of subtree) en vervang vertaal-attributen door huidige
// taal. Roep aan na elke render van nieuwe HTML die data-i18n-attributen
// bevat. applyI18n() wordt automatisch aangeroepen bij init en elke
// taal-wissel — voor dynamische content moet de app het zelf doen.
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
    // Vlag-knop: toon de TARGET taal (= klik om naar die taal te gaan).
    // Regional-indicator-emojis (🇳🇱/🇬🇧) renderen netjes op iOS, Android,
    // macOS — exact de doelgroep. Op Windows-browsers tonen ze als "NL"/
    // "GB" letter-paren (Windows mist de glyph), maar dat is voor de
    // public-app geen relevante use-case.
    const btn = document.getElementById('btn-lang');
    if (btn) {
        const targetLang = _i18nCurLang === 'nl' ? 'en' : 'nl';
        btn.textContent = targetLang === 'en' ? '🇬🇧' : '🇳🇱';
        btn.title = targetLang === 'en'
            ? 'Switch to English'
            : 'Wissel naar Nederlands';
    }
}

// Locale-string voor Intl-API's (toLocaleDateString, toLocaleTimeString):
//   getLocale()  →  'nl-NL' of 'en-GB'
function getLocale() {
    return _i18nCurLang === 'nl' ? 'nl-NL' : 'en-GB';
}
