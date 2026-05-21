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
    // Vlag-knop: toon de TARGET taal (= klik om naar die taal te gaan)
    // met inline SVG ipv emoji. Reden: Windows-browsers tonen flag-emojis
    // (🇳🇱/🇬🇧) als "NL"/"GB" letter-paren omdat Windows geen regional-
    // indicator-emojis ondersteunt. Inline SVG werkt overal hetzelfde.
    const btn = document.getElementById('btn-lang');
    if (btn) {
        const targetLang = _i18nCurLang === 'nl' ? 'en' : 'nl';
        btn.innerHTML = _langFlagSvg(targetLang);
        btn.title = targetLang === 'en'
            ? 'Switch to English'
            : 'Wissel naar Nederlands';
    }
}

// Inline-SVG vlaggen — werken op elk OS/browser, geen extra HTTP-call.
// Klein formaat (22×15 px) voor in header-knop-vakje van ~36×36.
function _langFlagSvg(lang) {
    const style = 'vertical-align:middle;border-radius:2px;display:block;margin:auto;box-shadow:0 0 0 1px rgba(0,0,0,.15)';
    if (lang === 'nl') {
        // NL: drie horizontale strepen
        return '<svg viewBox="0 0 9 6" width="22" height="15" style="' + style + '" aria-label="Nederlands">'
             + '<rect width="9" height="2" fill="#AE1C28"/>'
             + '<rect y="2" width="9" height="2" fill="#FFFFFF"/>'
             + '<rect y="4" width="9" height="2" fill="#21468B"/>'
             + '</svg>';
    }
    // Union Jack — vereenvoudigd maar herkenbaar
    return '<svg viewBox="0 0 60 30" width="22" height="15" style="' + style + '" aria-label="English">'
         + '<clipPath id="ujclip"><path d="M30,15 L60,30 v-15 z L60,0 v15 z L0,0 v15 z L0,30 v-15 z"/></clipPath>'
         + '<rect width="60" height="30" fill="#012169"/>'
         + '<path d="M0,0 L60,30 M60,0 L0,30" stroke="#FFFFFF" stroke-width="6"/>'
         + '<path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#ujclip)" stroke="#C8102E" stroke-width="4"/>'
         + '<path d="M30,0 v30 M0,15 h60" stroke="#FFFFFF" stroke-width="10"/>'
         + '<path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>'
         + '</svg>';
}

// Locale-string voor Intl-API's (toLocaleDateString, toLocaleTimeString):
//   getLocale()  →  'nl-NL' of 'en-GB'
function getLocale() {
    return _i18nCurLang === 'nl' ? 'nl-NL' : 'en-GB';
}
