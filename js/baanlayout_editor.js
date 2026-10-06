/* InlineComp — Baan-layout-editor (modal).
 *
 * Port van scratchpad/baanlayout_editor.html (PoC) naar een modal die vanuit
 * de banen-tab in beheer wordt geopend. 3 lagen (piste, wegparcours, infield)
 * met per sub-path keuze tussen stadium / freecurve / follow-piste.
 *
 * Interface:
 *   window.openBaanlayoutEditorModal({
 *       baanNaam: string,
 *       initial:  object | null,   // layout-state uit DB, of null voor nieuw
 *       onSave:   (data) => void,  // callback bij Opslaan, krijgt JSON-state
 *   });
 *
 * Dirty-check: Opslaan-knop pas enabled zodra iets gewijzigd is. Annuleren +
 * sluiten vraagt bevestiging als er niet-opgeslagen wijzigingen zijn.
 */
(function () {
    'use strict';

    const LAYERS_DEF = [
        { id: 'piste',   label: 'Piste',       type: 'band',    defWidth: 5.0, stroke: '#9a9a9a', defShape: 'stadium' },
        { id: 'weg',     label: 'Wegparcours', type: 'band',    defWidth: 4.5, stroke: '#b8b8b8', defShape: 'freecurve' },
        { id: 'infield', label: 'Infield',     type: 'polygon', defWidth: 0,   stroke: '#d0d0d0', defShape: 'follow-piste' },
    ];
    const DEFAULT_STADIUM = { A: { x: 30, y: 40 }, B: { x: 70, y: 40 }, diameter: 18 };

    // ── Factory per sub-path ─────────────────────────────────────────────
    function newSubPath(L, shapeType) {
        return {
            shapeType: shapeType || L.defShape,
            width: L.defWidth,
            visible: true,
            closed: true,
            stadium: structuredClone(DEFAULT_STADIUM),
            points: [],
        };
    }
    function ensureSubPathFields(S, L) {
        for (const P of S.paths) {
            if (P.shapeType == null) P.shapeType = L.defShape;
            if (P.width == null)     P.width     = L.defWidth;
            if (P.visible == null)   P.visible   = true;
            if (P.closed == null)    P.closed    = true;
            if (P.stadium == null)   P.stadium   = structuredClone(DEFAULT_STADIUM);
            if (P.points == null)    P.points    = [];
        }
    }

    // Verse state (= layout_data in DB-formaat). initial = null → start met
    // één sub-path per laag (= PoC-default).
    function freshState() {
        const st = { activeLayer: 'piste', imgDataUrl: null, imgAspect: 4/3, layers: {} };
        for (const L of LAYERS_DEF) {
            st.layers[L.id] = {
                width: L.defWidth,
                infieldMargin: 0.5,
                paths: [ newSubPath(L) ],
                activePath: 0,
            };
        }
        return st;
    }
    function hydrateState(initial) {
        const st = freshState();
        if (!initial || typeof initial !== 'object') return st;
        if (initial.imgAspect) st.imgAspect = initial.imgAspect;
        if (initial.layers) {
            for (const L of LAYERS_DEF) {
                const src = initial.layers[L.id];
                if (!src) continue;
                st.layers[L.id] = {
                    width:         src.width         ?? L.defWidth,
                    infieldMargin: src.infieldMargin ?? 0.5,
                    paths:         Array.isArray(src.paths) && src.paths.length ? src.paths : [ newSubPath(L) ],
                    activePath:    src.activePath    ?? 0,
                };
                ensureSubPathFields(st.layers[L.id], L);
            }
        }
        return st;
    }

    // ── Modal open/sluit ─────────────────────────────────────────────────
    function openModal({ baanNaam, initial, onSave }) {
        // Alleen één instantie tegelijk.
        if (document.querySelector('.bl-overlay')) return;

        const state = hydrateState(initial);
        let dirty = false;
        const markDirty = () => {
            if (dirty) return;
            dirty = true;
            overlay.querySelector('.bl-dirty').hidden = false;
            overlay.querySelector('#bl-opslaan').disabled = false;
        };

        const overlay = document.createElement('div');
        overlay.className = 'bl-overlay';
        overlay.innerHTML = `
            <div class="bl-modal">
                <div class="bl-header">
                    <h2>Baan-layout — ${escHtml(baanNaam || '')}</h2>
                    <span class="bl-dirty" hidden>• niet opgeslagen</span>
                    <button class="btn-secondary" id="bl-annuleer" type="button">Annuleren</button>
                    <button class="btn-primary" id="bl-opslaan" type="button" disabled>Opslaan</button>
                </div>

                <div class="bl-toolbar">
                    <label for="bl-file">Satelliet-foto:</label>
                    <input type="file" id="bl-file" accept="image/png,image/jpeg">
                    <button class="btn-secondary btn-small" id="bl-reset-all" type="button">Reset alles</button>
                    <span style="flex:1"></span>
                    <label style="display:flex;gap:4px;align-items:center">
                        <input type="checkbox" id="bl-show-sat" checked> Satelliet tonen
                    </label>
                </div>

                <div class="bl-main">
                    <div class="bl-canvas-wrap">
                        <div class="bl-stage" id="bl-stage">
                            <img class="bl-stage-img" id="bl-stage-img" alt="" hidden>
                            <svg class="bl-stage-svg" id="bl-stage-svg" viewBox="0 0 100 75" preserveAspectRatio="xMidYMid meet"></svg>
                            <div class="bl-stage-empty" id="bl-stage-empty">Upload optioneel een satelliet-foto als tracing-hulp, of ga meteen aan de slag met stadium/freecurve.</div>
                        </div>
                        <div class="bl-seg-popup" id="bl-seg-popup" hidden>
                            <button data-seg-type="line">Recht</button>
                            <button data-seg-type="curve">Bocht</button>
                        </div>
                    </div>

                    <div class="bl-side">
                        <div class="bl-panel">
                            <h3>Laag</h3>
                            <div class="bl-layers" id="bl-layer-list"></div>
                        </div>
                        <div class="bl-panel">
                            <h3 id="bl-layer-title">Instellingen</h3>
                            <div class="bl-ctrls" id="bl-layer-ctrls"></div>
                        </div>
                        <div class="bl-panel">
                            <h3>Help</h3>
                            <div class="bl-help">
                                <p><strong>Klik</strong> op het canvas om een punt toe te voegen (freecurve).</p>
                                <p><strong>Sleep</strong> een punt om 'm te verplaatsen · <strong>Shift-klik</strong> verwijdert.</p>
                                <p><strong>Dubbelklik</strong> tussen 2 punten voegt een nieuw punt in.</p>
                                <p><strong>Klik op een track-segment</strong> → kies <em>Recht</em> of <em>Bocht</em>.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bl-footer">
                    <button class="btn-secondary" id="bl-annuleer-2" type="button">Annuleren</button>
                    <button class="btn-primary" id="bl-opslaan-2" type="button" disabled>Opslaan</button>
                </div>
            </div>`;
        document.body.appendChild(overlay);

        // ── Element-refs (scoped binnen de modal) ──
        const stage       = overlay.querySelector('#bl-stage');
        const stageImg    = overlay.querySelector('#bl-stage-img');
        const stageSvg    = overlay.querySelector('#bl-stage-svg');
        const stageEmpty  = overlay.querySelector('#bl-stage-empty');
        const fileInput   = overlay.querySelector('#bl-file');
        const clearAllBtn = overlay.querySelector('#bl-reset-all');
        const layerList   = overlay.querySelector('#bl-layer-list');
        const layerTitle  = overlay.querySelector('#bl-layer-title');
        const layerCtrls  = overlay.querySelector('#bl-layer-ctrls');
        const segPopup    = overlay.querySelector('#bl-seg-popup');
        const showSatChk  = overlay.querySelector('#bl-show-sat');
        const btnOpslaan1 = overlay.querySelector('#bl-opslaan');
        const btnOpslaan2 = overlay.querySelector('#bl-opslaan-2');
        const btnAnnul1   = overlay.querySelector('#bl-annuleer');
        const btnAnnul2   = overlay.querySelector('#bl-annuleer-2');

        // Dirty-flag ook via de 2e opslaan-knop activeren
        const _origMarkDirty = markDirty;
        // Beide opslaan-knoppen enablen bij dirty
        const syncOpslaanKnoppen = () => {
            btnOpslaan1.disabled = !dirty;
            btnOpslaan2.disabled = !dirty;
        };

        function getLayerDef(id) { return LAYERS_DEF.find(l => l.id === id); }
        function totalPoints(layerId) {
            return state.layers[layerId].paths.reduce((n, p) => n + p.points.length, 0);
        }

        // ── Render laag-lijst ──
        // Een laag geldt als "aan" zodra minstens één sub-path visible is.
        // De 👁/⊘-knop toggelt visible op ALLE sub-paths van die laag — zo
        // kun je bv. de piste in één klik uitzetten als je alleen een
        // wegparcours wilt tekenen.
        function isLayerVisible(lid) {
            return state.layers[lid].paths.some(p => p.visible !== false);
        }
        function setLayerVisible(lid, on) {
            for (const P of state.layers[lid].paths) P.visible = on;
        }
        function renderLayerList() {
            layerList.innerHTML = '';
            for (const L of LAYERS_DEF) {
                const btn = document.createElement('button');
                btn.className = 'bl-layer-btn' + (state.activeLayer === L.id ? ' active' : '');
                btn.type = 'button';
                const nSub = state.layers[L.id].paths.length;
                const nPts = totalPoints(L.id);
                const vis  = isLayerVisible(L.id);
                btn.innerHTML = `
                    <span class="bl-layer-vis" title="${vis ? 'Laag uitzetten' : 'Laag aanzetten'}">${vis ? '👁' : '⊘'}</span>
                    <span class="bl-layer-dot" data-layer="${L.id}"></span>
                    <span>${escHtml(L.label)}</span>
                    <span class="bl-layer-count">${nSub} sub · ${nPts} pt</span>
                `;
                btn.querySelector('.bl-layer-vis').addEventListener('click', (ev) => {
                    ev.stopPropagation();
                    setLayerVisible(L.id, !vis);
                    markDirty();
                    renderLayerList();
                    renderControls();
                    redraw();
                });
                btn.addEventListener('click', () => {
                    state.activeLayer = L.id;
                    renderLayerList();
                    renderControls();
                });
                layerList.appendChild(btn);
            }
        }

        // ── Render laag-controls (incl. sub-paths) ──
        function renderControls() {
            const L = getLayerDef(state.activeLayer);
            const S = state.layers[L.id];
            ensureSubPathFields(S, L);
            layerTitle.textContent = L.label;
            layerCtrls.innerHTML = '';

            // Infield: layer-level marge-slider
            if (L.id === 'infield') {
                const row = document.createElement('div');
                row.className = 'bl-ctrl-row';
                row.innerHTML = `
                    <label>Marge (follow-piste)</label>
                    <input type="range" min="0" max="5" step="0.1" value="${S.infieldMargin}">
                    <span class="bl-ctrl-val">${S.infieldMargin.toFixed(1)}</span>
                `;
                const sl = row.querySelector('input');
                const vl = row.querySelector('.bl-ctrl-val');
                sl.addEventListener('input', (e) => {
                    S.infieldMargin = parseFloat(e.target.value);
                    vl.textContent = S.infieldMargin.toFixed(1);
                    markDirty();
                    redraw();
                });
                layerCtrls.appendChild(row);
            }

            // Sub-paths-header
            const subHdr = document.createElement('div');
            subHdr.style.cssText = 'font-size:.72rem;color:#67748a;font-weight:700;text-transform:uppercase;letter-spacing:.06em;margin-top:4px;';
            subHdr.textContent = 'Sub-paths';
            layerCtrls.appendChild(subHdr);

            const subList = document.createElement('div');
            subList.className = 'bl-sub-list';
            for (let i = 0; i < S.paths.length; i++) {
                subList.appendChild(buildSubPathRow(L, S, i));
            }
            layerCtrls.appendChild(subList);

            // Alleen wegparcours en infield mogen meerdere sub-paths hebben
            // (piste = 1 baan per vereniging).
            if (L.id !== 'piste') {
                const addBtn = document.createElement('button');
                addBtn.type = 'button';
                addBtn.className = 'bl-btn-add-sub';
                addBtn.textContent = '+ Nieuwe sub-path';
                addBtn.addEventListener('click', () => {
                    S.paths.push(newSubPath(L, 'freecurve'));
                    S.activePath = S.paths.length - 1;
                    markDirty();
                    renderControls();
                    renderLayerList();
                    redraw();
                });
                layerCtrls.appendChild(addBtn);
            }

            // Laag-acties: laatste punt wissen + laag resetten
            const actions = document.createElement('div');
            actions.className = 'bl-actions';
            actions.innerHTML = `
                <button type="button" class="bl-btn-add-sub" id="bl-undo">Laatste punt wissen</button>
                <button type="button" class="bl-btn-add-sub" id="bl-clear-layer">Wis laag</button>
            `;
            actions.querySelector('#bl-undo').addEventListener('click', () => {
                const P = S.paths[S.activePath];
                if (P && P.points.length) {
                    P.points.pop();
                    markDirty();
                    redraw();
                    renderLayerList();
                    renderControls();
                }
            });
            actions.querySelector('#bl-clear-layer').addEventListener('click', () => {
                S.paths = [ newSubPath(L) ];
                S.activePath = 0;
                markDirty();
                redraw();
                renderLayerList();
                renderControls();
            });
            layerCtrls.appendChild(actions);
        }

        // Eén sub-path-rij UI
        function buildSubPathRow(L, S, i) {
            const P = S.paths[i];
            const row = document.createElement('div');
            row.className = 'bl-sub-row' + (i === S.activePath ? ' active' : '');

            const shapeOpts = L.id === 'piste'
                ? [['stadium', 'Stadium'], ['freecurve', 'Vrije curve']]
                : L.id === 'infield'
                    ? [['follow-piste', 'Volg piste'], ['freecurve', 'Vrije curve']]
                    : [['freecurve', 'Vrije curve'], ['stadium', 'Stadium']];

            const shapeHtml = shapeOpts.length > 1
                ? `<select class="bl-sub-shape">${shapeOpts.map(([v, l]) =>
                    `<option value="${v}" ${P.shapeType === v ? 'selected' : ''}>${l}</option>`
                  ).join('')}</select>`
                : '';
            const widthHtml = (L.type === 'band')
                ? `<div class="bl-sub-ctrl"><label>Breedte</label><input type="range" class="bl-sub-width" min="1" max="15" step="0.1" value="${P.width}"><span class="bl-sub-ctrl-val">${P.width.toFixed(1)}</span></div>`
                : '';
            const diamHtml = (P.shapeType === 'stadium')
                ? `<div class="bl-sub-ctrl"><label>Diameter</label><input type="range" class="bl-sub-diam" min="4" max="60" step="0.5" value="${P.stadium.diameter}"><span class="bl-sub-ctrl-val">${P.stadium.diameter.toFixed(1)}</span></div>`
                : '';
            const closedHtml = (L.type === 'band' && P.shapeType === 'freecurve')
                ? `<div class="bl-sub-ring"><label><input type="checkbox" class="bl-sub-closed" ${P.closed ? 'checked' : ''}> ring (gesloten)</label></div>`
                : '';
            const labelText = P.shapeType === 'stadium'
                ? `Sub-path ${i + 1} (stadium)`
                : P.shapeType === 'follow-piste'
                    ? `Sub-path ${i + 1} (volg piste)`
                    : `Sub-path ${i + 1} (${P.points.length} pt)`;

            row.innerHTML = `
                <div class="bl-sub-head">
                    <button type="button" class="bl-sub-vis" title="${P.visible ? 'Verberg' : 'Toon'}">${P.visible ? '👁' : '⊘'}</button>
                    <span class="bl-sub-label">${escHtml(labelText)}</span>
                    ${shapeHtml}
                    <button type="button" class="bl-sub-del" title="Verwijder sub-path">×</button>
                </div>
                ${widthHtml}
                ${diamHtml}
                ${closedHtml}
            `;

            row.querySelector('.bl-sub-label').addEventListener('click', () => {
                S.activePath = i;
                renderControls();
                redraw();
            });
            row.querySelector('.bl-sub-vis').addEventListener('click', (e) => {
                e.stopPropagation();
                P.visible = !P.visible;
                markDirty();
                renderControls();
                redraw();
            });
            row.querySelector('.bl-sub-del').addEventListener('click', (e) => {
                e.stopPropagation();
                if (S.paths.length === 1) {
                    S.paths[0] = newSubPath(L);
                } else {
                    S.paths.splice(i, 1);
                    if (S.activePath >= S.paths.length) S.activePath = S.paths.length - 1;
                }
                markDirty();
                renderControls();
                renderLayerList();
                redraw();
            });
            const widthSl = row.querySelector('.bl-sub-width');
            if (widthSl) widthSl.addEventListener('input', (e) => {
                P.width = parseFloat(e.target.value);
                row.querySelector('.bl-sub-ctrl-val').textContent = P.width.toFixed(1);
                markDirty();
                redraw();
            });
            const diamSl = row.querySelector('.bl-sub-diam');
            if (diamSl) diamSl.addEventListener('input', (e) => {
                P.stadium.diameter = parseFloat(e.target.value);
                diamSl.parentElement.querySelector('.bl-sub-ctrl-val').textContent = P.stadium.diameter.toFixed(1);
                markDirty();
                redraw();
            });
            const shapeSel = row.querySelector('.bl-sub-shape');
            if (shapeSel) shapeSel.addEventListener('change', (e) => {
                P.shapeType = e.target.value;
                markDirty();
                renderControls();
                renderLayerList();
                redraw();
            });
            const closedChk = row.querySelector('.bl-sub-closed');
            if (closedChk) closedChk.addEventListener('change', (e) => {
                P.closed = e.target.checked;
                markDirty();
                redraw();
            });

            return row;
        }

        // ── Satelliet-upload ──
        fileInput.addEventListener('change', (e) => {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = (ev) => {
                state.imgDataUrl = ev.target.result;
                const img = new Image();
                img.onload = () => {
                    state.imgAspect = img.naturalWidth / img.naturalHeight;
                    stage.style.aspectRatio = state.imgAspect;
                    stageImg.src = state.imgDataUrl;
                    stageImg.hidden = false;
                    stageEmpty.hidden = true;
                    stageSvg.setAttribute('viewBox', `0 0 100 ${100 / state.imgAspect}`);
                    redraw();
                };
                img.src = state.imgDataUrl;
            };
            reader.readAsDataURL(file);
        });

        clearAllBtn.addEventListener('click', async () => {
            const ok = (typeof toonBevestigDialog === 'function')
                ? await toonBevestigDialog('Alle lagen wissen? Niet-opgeslagen tekenwerk gaat verloren.', 'Reset', 'Resetten', 'Annuleren')
                : confirm('Alle lagen wissen?');
            if (!ok) return;
            for (const L of LAYERS_DEF) {
                state.layers[L.id] = { width: L.defWidth, infieldMargin: 0.5, paths: [ newSubPath(L) ], activePath: 0 };
            }
            markDirty();
            redraw();
            renderLayerList();
            renderControls();
        });
        showSatChk.addEventListener('change', redraw);

        // ── Geometry helpers (stadium + Catmull-Rom met segment-type) ──
        function stadiumD(A, B, D) {
            const dx = B.x - A.x, dy = B.y - A.y;
            const L = Math.hypot(dx, dy) || 1;
            const ux = dx / L, uy = dy / L;
            const nx = -uy, ny = ux;
            const r = D / 2;
            const P1 = { x: A.x + nx * r, y: A.y + ny * r };
            const P2 = { x: B.x + nx * r, y: B.y + ny * r };
            const P3 = { x: B.x - nx * r, y: B.y - ny * r };
            const P4 = { x: A.x - nx * r, y: A.y - ny * r };
            return `M ${P1.x.toFixed(2)},${P1.y.toFixed(2)} `
                 + `L ${P2.x.toFixed(2)},${P2.y.toFixed(2)} `
                 + `A ${r.toFixed(2)},${r.toFixed(2)} 0 0 0 ${P3.x.toFixed(2)},${P3.y.toFixed(2)} `
                 + `L ${P4.x.toFixed(2)},${P4.y.toFixed(2)} `
                 + `A ${r.toFixed(2)},${r.toFixed(2)} 0 0 0 ${P1.x.toFixed(2)},${P1.y.toFixed(2)} Z`;
        }

        function catmullRomD(points, closed) {
            const n = points.length;
            if (n < 2) return '';
            const get = (i) => closed
                ? points[((i % n) + n) % n]
                : points[Math.max(0, Math.min(n - 1, i))];
            const segTypeAt = (i) => {
                if (!closed && (i < 0 || i >= n - 1)) return 'line';
                return (get(i).segType || 'line');
            };
            let d = `M ${points[0].x.toFixed(2)},${points[0].y.toFixed(2)} `;
            const segs = closed ? n : n - 1;
            for (let i = 0; i < segs; i++) {
                const p0 = get(i - 1), p1 = get(i), p2 = get(i + 1), p3 = get(i + 2);
                if (segTypeAt(i) === 'line') {
                    d += `L ${p2.x.toFixed(2)},${p2.y.toFixed(2)} `;
                    continue;
                }
                const prevIsLine = segTypeAt(i - 1) === 'line';
                const nextIsLine = segTypeAt(i + 1) === 'line';
                const dist = Math.hypot(p2.x - p1.x, p2.y - p1.y) || 1;
                const tLen = dist / 3;
                let c1x, c1y, c2x, c2y;
                if (prevIsLine) {
                    const dx = p1.x - p0.x, dy = p1.y - p0.y;
                    const L = Math.hypot(dx, dy) || 1;
                    c1x = p1.x + (dx / L) * tLen; c1y = p1.y + (dy / L) * tLen;
                } else {
                    c1x = p1.x + (p2.x - p0.x) / 6; c1y = p1.y + (p2.y - p0.y) / 6;
                }
                if (nextIsLine) {
                    const dx = p2.x - p3.x, dy = p2.y - p3.y;
                    const L = Math.hypot(dx, dy) || 1;
                    c2x = p2.x + (dx / L) * tLen; c2y = p2.y + (dy / L) * tLen;
                } else {
                    c2x = p2.x - (p3.x - p1.x) / 6; c2y = p2.y - (p3.y - p1.y) / 6;
                }
                d += `C ${c1x.toFixed(2)},${c1y.toFixed(2)} ${c2x.toFixed(2)},${c2y.toFixed(2)} ${p2.x.toFixed(2)},${p2.y.toFixed(2)} `;
            }
            if (closed) d += 'Z';
            return d.trim();
        }

        function pathForSubPath(L, S, P) {
            if (P.shapeType === 'stadium') {
                return stadiumD(P.stadium.A, P.stadium.B, P.stadium.diameter);
            }
            if (P.shapeType === 'follow-piste') {
                const pisteP = state.layers.piste.paths[0];
                if (!pisteP || pisteP.shapeType !== 'stadium') return '';
                const innerD = Math.max(0.5, pisteP.stadium.diameter - pisteP.width - state.layers[L.id].infieldMargin * 2);
                return stadiumD(pisteP.stadium.A, pisteP.stadium.B, innerD);
            }
            if (!P.points.length) return '';
            return catmullRomD(P.points, L.type === 'polygon' ? true : P.closed);
        }

        function distToSegment(p, a, b) {
            const dx = b.x - a.x, dy = b.y - a.y;
            const len2 = dx * dx + dy * dy || 1;
            let t = ((p.x - a.x) * dx + (p.y - a.y) * dy) / len2;
            t = Math.max(0, Math.min(1, t));
            const cx = a.x + t * dx, cy = a.y + t * dy;
            return Math.hypot(p.x - cx, p.y - cy);
        }

        function svgPointFromEvent(ev) {
            const rect = stageSvg.getBoundingClientRect();
            const vb = stageSvg.viewBox.baseVal;
            return {
                x: ((ev.clientX - rect.left) / rect.width)  * vb.width,
                y: ((ev.clientY - rect.top)  / rect.height) * vb.height,
            };
        }

        // ── Pointer-interactie: punt-toevoegen/slepen/handles ──
        let dragging = null;
        let pendingPopupTimer = null;

        stageSvg.addEventListener('pointerdown', (ev) => {
            if (ev.target.classList && ev.target.classList.contains('bl-pt')) {
                const li = ev.target.dataset.layer;
                state.activeLayer = li;
                if (ev.target.dataset.handle) {
                    const subIdx = parseInt(ev.target.dataset.sub, 10);
                    state.layers[li].activePath = subIdx;
                    dragging = { kind: 'handle', layerId: li, subIdx, handle: ev.target.dataset.handle };
                    ev.target.classList.add('dragging');
                    stageSvg.setPointerCapture(ev.pointerId);
                    renderLayerList();
                    renderControls();
                    return;
                }
                const subIdx = parseInt(ev.target.dataset.sub, 10);
                const ptIdx  = parseInt(ev.target.dataset.idx, 10);
                if (ev.shiftKey) {
                    state.layers[li].paths[subIdx].points.splice(ptIdx, 1);
                    markDirty();
                    redraw();
                    renderLayerList();
                    renderControls();
                    return;
                }
                state.layers[li].activePath = subIdx;
                renderLayerList();
                renderControls();
                dragging = { kind: 'pt', layerId: li, subIdx, ptIdx };
                ev.target.classList.add('dragging');
                stageSvg.setPointerCapture(ev.pointerId);
                return;
            }
            // Lege klik: alleen zinvol voor freecurve.
            const S = state.layers[state.activeLayer];
            const P = S.paths[S.activePath];
            if (!P || P.shapeType !== 'freecurve') return;
            const p = svgPointFromEvent(ev);
            if (P.points.length >= 2) {
                const nPts = P.points.length;
                const segs = P.closed ? nPts : nPts - 1;
                const TOL = 1.5;
                let bestI = -1, bestD = Infinity;
                for (let i = 0; i < segs; i++) {
                    const a = P.points[i], b = P.points[(i + 1) % nPts];
                    const d = distToSegment(p, a, b);
                    if (d < bestD && d < TOL) { bestD = d; bestI = i; }
                }
                if (bestI >= 0) {
                    const clientX = ev.clientX, clientY = ev.clientY;
                    clearTimeout(pendingPopupTimer);
                    pendingPopupTimer = setTimeout(() => {
                        showSegPopup({ clientX, clientY }, state.activeLayer, S.activePath, bestI);
                        pendingPopupTimer = null;
                    }, 280);
                    return;
                }
            }
            P.points.push(p);
            markDirty();
            redraw();
            renderLayerList();
            renderControls();
        });

        stageSvg.addEventListener('pointermove', (ev) => {
            if (!dragging) return;
            const p = svgPointFromEvent(ev);
            if (dragging.kind === 'handle') {
                state.layers[dragging.layerId].paths[dragging.subIdx].stadium[dragging.handle] = p;
            } else {
                state.layers[dragging.layerId].paths[dragging.subIdx].points[dragging.ptIdx] = p;
            }
            markDirty();
            redraw();
        });
        stageSvg.addEventListener('pointerup', (ev) => {
            if (dragging) {
                overlay.querySelectorAll('.bl-pt.dragging').forEach(el => el.classList.remove('dragging'));
                try { stageSvg.releasePointerCapture(ev.pointerId); } catch {}
                dragging = null;
            }
        });
        stageSvg.addEventListener('dblclick', (ev) => {
            clearTimeout(pendingPopupTimer);
            pendingPopupTimer = null;
            const S = state.layers[state.activeLayer];
            const P = S.paths[S.activePath];
            if (!P || P.shapeType !== 'freecurve') return;
            if (P.points.length < 2) return;
            const p = svgPointFromEvent(ev);
            const nPts = P.points.length;
            const segs = P.closed ? nPts : nPts - 1;
            let bestI = 0, bestD = Infinity;
            for (let i = 0; i < segs; i++) {
                const d = distToSegment(p, P.points[i], P.points[(i + 1) % nPts]);
                if (d < bestD) { bestD = d; bestI = i; }
            }
            P.points.splice(bestI + 1, 0, p);
            markDirty();
            redraw();
            renderLayerList();
            renderControls();
        });

        // ── Segment-type-popup (recht / bocht) ──
        let activeSeg = null;
        function showSegPopup(ev, layerId, subIdx, segIdx) {
            activeSeg = { layerId, subIdx, segIdx };
            const curType = (state.layers[layerId].paths[subIdx].points[segIdx].segType || 'line');
            segPopup.querySelectorAll('button').forEach(b => {
                b.classList.toggle('active', b.dataset.segType === curType);
            });
            segPopup.style.left = ev.clientX + 'px';
            segPopup.style.top  = ev.clientY + 'px';
            segPopup.hidden = false;
        }
        function hideSegPopup() {
            segPopup.hidden = true;
            activeSeg = null;
        }
        segPopup.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                if (!activeSeg) return;
                const { layerId, subIdx, segIdx } = activeSeg;
                state.layers[layerId].paths[subIdx].points[segIdx].segType = btn.dataset.segType;
                // Popup NIET auto-sluiten: zo zie je direct de active-highlight
                // op de gekozen knop (voorheen sloot 'ie te snel waardoor
                // feedback miste). Buiten-klik of ESC sluit de popup wel.
                segPopup.querySelectorAll('button').forEach(b => {
                    b.classList.toggle('active', b.dataset.segType === btn.dataset.segType);
                });
                markDirty();
                redraw();
            });
        });
        // Buiten-klik sluit de popup. Document-level capture-phase vangt
        // alles vóór iedere andere handler; stopPropagation voorkomt dat de
        // sluit-klik ook een freecurve-punt zet.
        const popupBuitenKlik = (ev) => {
            if (segPopup.hidden) return;
            if (segPopup.contains(ev.target)) return;
            hideSegPopup();
            ev.stopPropagation();
            ev.preventDefault();
        };
        document.addEventListener('pointerdown', popupBuitenKlik, true);

        // ── Render editor-overlay (handles + strokes) ──
        function redraw() {
            const parts = [];
            for (const L of LAYERS_DEF) {
                const S = state.layers[L.id];
                ensureSubPathFields(S, L);
                for (const P of S.paths) {
                    if (!P.visible) continue;
                    const d = pathForSubPath(L, S, P);
                    if (!d) continue;
                    if (L.type === 'band') {
                        parts.push(`<path class="bl-stroke-${L.id}" d="${d}" stroke-width="${P.width}" />`);
                    } else {
                        parts.push(`<path class="bl-fill-infield" d="${d}" />`);
                    }
                }
            }
            for (const L of LAYERS_DEF) {
                const S = state.layers[L.id];
                const isActiveLayer = state.activeLayer === L.id;
                for (let s = 0; s < S.paths.length; s++) {
                    const P = S.paths[s];
                    if (!P.visible) continue;
                    const isActiveSub = isActiveLayer && s === S.activePath;
                    if (P.shapeType === 'stadium') {
                        const sz = isActiveSub ? 1.4 : 1.0;
                        parts.push(`<circle class="bl-pt ${L.id} stadium-handle${isActiveSub ? ' active-sub' : ''}" data-layer="${L.id}" data-sub="${s}" data-handle="A" cx="${P.stadium.A.x.toFixed(2)}" cy="${P.stadium.A.y.toFixed(2)}" r="${sz}" />`);
                        parts.push(`<circle class="bl-pt ${L.id} stadium-handle${isActiveSub ? ' active-sub' : ''}" data-layer="${L.id}" data-sub="${s}" data-handle="B" cx="${P.stadium.B.x.toFixed(2)}" cy="${P.stadium.B.y.toFixed(2)}" r="${sz}" />`);
                    } else if (P.shapeType === 'freecurve') {
                        const r = isActiveSub ? 1.1 : 0.8;
                        P.points.forEach((p, i) => {
                            const cls = `bl-pt ${L.id}${isActiveSub ? ' active-sub' : ''}`;
                            parts.push(`<circle class="${cls}" data-layer="${L.id}" data-sub="${s}" data-idx="${i}" cx="${p.x.toFixed(2)}" cy="${p.y.toFixed(2)}" r="${r}" />`);
                        });
                    }
                }
            }
            stageSvg.innerHTML = parts.join('');
            // Satelliet-visibility sync met checkbox
            if (stageImg.src) stageImg.hidden = !showSatChk.checked;
        }

        // ── Opslaan / Annuleren ──
        function serialize() {
            // Compacte state zonder editor-only velden (activePath, imgDataUrl).
            // De satelliet-foto wordt bewust NIET opgeslagen (groot, en bedoeld
            // als eenmalige tracing-hulp — bij volgende bewerk-sessie upload je 'm
            // opnieuw indien gewenst).
            const out = { imgAspect: state.imgAspect, layers: {} };
            for (const L of LAYERS_DEF) {
                const S = state.layers[L.id];
                out.layers[L.id] = {
                    width: S.width,
                    infieldMargin: S.infieldMargin,
                    paths: S.paths.map(P => ({
                        shapeType: P.shapeType,
                        width: P.width,
                        visible: P.visible,
                        closed: P.closed,
                        stadium: P.stadium,
                        points: P.points,
                    })),
                };
            }
            return out;
        }

        async function sluit(forceer) {
            if (!forceer && dirty) {
                const ok = (typeof toonBevestigDialog === 'function')
                    ? await toonBevestigDialog(
                        'Je hebt niet-opgeslagen wijzigingen. Weet je zeker dat je de editor wilt sluiten?',
                        'Niet opgeslagen', 'Sluiten', 'Annuleren')
                    : confirm('Niet-opgeslagen wijzigingen gaan verloren. Doorgaan?');
                if (!ok) return;
            }
            overlay.remove();
        }
        btnAnnul1.addEventListener('click', () => sluit(false));
        btnAnnul2.addEventListener('click', () => sluit(false));

        async function opslaan() {
            btnOpslaan1.disabled = true;
            btnOpslaan2.disabled = true;
            try {
                await onSave(serialize());
                dirty = false;
                overlay.querySelector('.bl-dirty').hidden = true;
                sluit(true);
            } catch (e) {
                // onSave zelf toont de foutmelding; hier opnieuw enabled zodat
                // user kan retrien.
                btnOpslaan1.disabled = false;
                btnOpslaan2.disabled = false;
            }
        }
        btnOpslaan1.addEventListener('click', opslaan);
        btnOpslaan2.addEventListener('click', opslaan);

        // ESC-key: eerst popup sluiten (als die open staat), anders de modal.
        const escHandler = (e) => {
            if (e.key !== 'Escape') return;
            if (!segPopup.hidden) { hideSegPopup(); return; }
            sluit(false);
        };
        // Capture-phase zodat we vóór een eventuele <select>/range-input
        // ESC-afhandeling of andere document-level ESC-handlers vangen.
        document.addEventListener('keydown', escHandler, true);
        // Opruimen bij remove: observer op overlay-removal (verwijdert óók
        // de document-level popup-buiten-klik-listener zodat die niet blijft
        // hangen voor andere modals).
        const obs = new MutationObserver(() => {
            if (!document.body.contains(overlay)) {
                document.removeEventListener('keydown', escHandler, true);
                document.removeEventListener('pointerdown', popupBuitenKlik, true);
                obs.disconnect();
            }
        });
        obs.observe(document.body, { childList: true });

        // ── Initiele render ──
        renderLayerList();
        renderControls();
        redraw();
    }

    // ── Mini-escaping (voor als `escHtml` niet globaal is) ──
    function escHtml(s) {
        if (typeof window.escHtml === 'function') return window.escHtml(s);
        return String(s ?? '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Publiek entry-point
    window.openBaanlayoutEditorModal = openModal;
})();
