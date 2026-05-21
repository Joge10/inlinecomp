// ============================================================
//  InlineComp – shared i18n helpers
//
//  Eén centraal bestand met de vertaal-infrastructuur, herbruikt door
//  alle apps: public (rijder), coach (toekomst), jury (toekomst) en
//  uiteindelijk ook het admin-deel.
//
//  Gebruik in een app:
//      <!-- PHP-side include zodat geen extra HTTP-request nodig is: -->
//      <script>
//      <?php readfile(__DIR__ . '/../js/i18n.js'); ?>
//
//      // App-specifiek T-object (eigen woordenboek per app):
//      const T_APP = {
//          nl: { titel: 'Mijn App', knop_opslaan: 'Opslaan', ... },
//          en: { titel: 'My App',   knop_opslaan: 'Save',     ... },
//      };
//
//      // Init bij DOM-ready. onChange is optioneel — gebruik als de app
//      // dynamische content rendert die opnieuw moet bij taal-wissel
//      // (bv. herrendering van een actieve tab of lijst).
//      document.addEventListener('DOMContentLoaded', () => {
//          initI18n({
//              dict:     T_APP,
//              onChange: () => myAppRerender(),  // optioneel
//          });
//      });
//      </script>
//
//      <!-- In de header van de app: vlag-knop met dit ID -->
//      <button class="btn-help" id="btn-lang" title="Language / Taal">🇬🇧</button>
//
//  Marker-attributen in HTML (vervangen automatisch bij applyI18n):
//      <span data-i18n="key">                     → element.textContent
//      <div  data-i18n-html="key">                → element.innerHTML
//      <button data-i18n-title="key">             → element.title
//      <input  data-i18n-placeholder="key">       → element.placeholder
//
//  In JS-template-literals:
//      `<button>${esc(t('knop_opslaan'))}</button>`
//      `${t('aantal_rijders', { n: 5 })}`         // {n} → 5
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
    // Vlag-knop label: toon de TARGET taal (= 🇬🇧 als app NL is, omdat
    // klik = wissel naar EN). Self-explanatory voor gebruiker.
    const btn = document.getElementById('btn-lang');
    if (btn) btn.textContent = _i18nCurLang === 'nl' ? '🇬🇧' : '🇳🇱';
}

// Locale-string voor Intl-API's (toLocaleDateString, toLocaleTimeString):
//   getLocale()  →  'nl-NL' of 'en-GB'
function getLocale() {
    return _i18nCurLang === 'nl' ? 'nl-NL' : 'en-GB';
}
