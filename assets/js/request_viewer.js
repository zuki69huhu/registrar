/*
 * Modal viewer for a filed request, shown as the paper form it replaces.
 * Pair with request_viewer.css, and link request_form.css for the sheet itself.
 *
 * Like doc_viewer.js it injects its own markup, so a page only needs the two
 * stylesheets, request_form.js (which fits each answer to its line), this
 * script, and a call:
 *
 *   openRequestForm({
 *       id:       7,
 *       title:    'Request for Form 137-A',
 *       subtitle: '#00007 · Suyu, Daryl R. · Filed Sep 20, 2026',
 *       status:   { label: 'Pending', color: '#f59e0b' },   // optional
 *       theme:    'dark',                                   // optional
 *       actions:  [{ label, icon, className, onClick }]     // optional footer
 *       allowPrint:    false,       // optional, default true
 *       allowDownload: false        // optional, default true
 *   });
 *
 * The two flags are what makes the student's viewer read-only: they only look at
 * the filed form, and the paper they may keep is the claim slip, not the form.
 * The registrar's viewer leaves both on.
 *
 * The sheet is fetched from request_form_view.php, which authorises the viewer,
 * so a student only ever gets their own and a registrar only their institution's.
 * Zoom scales the whole sheet with a CSS transform, so the text stays sharp at
 * every level; the controls mirror doc_viewer.js — toolbar, +/-/0, Ctrl+wheel,
 * pinch, double-tap, and drag to pan.
 */

const REQUEST_VIEWER_MARKUP = `
<div class="rv" id="rvRoot" role="dialog" aria-modal="true" aria-labelledby="rvTitle" hidden>
    <div class="rv-backdrop" data-rv-close></div>
    <div class="rv-dialog" tabindex="-1">
        <div class="rv-head">
            <div class="rv-titles">
                <h3 id="rvTitle">Request</h3>
                <p id="rvSub"></p>
            </div>
            <div class="rv-head-right">
                <span class="rv-status" id="rvStatus"></span>
                <button type="button" class="rv-x" data-rv-close title="Close (Esc)" aria-label="Close">&times;</button>
            </div>
        </div>

        <div class="rv-toolbar" role="toolbar" aria-label="Form controls">
            <button type="button" class="rv-tool" id="rvOut" title="Zoom out (-)" aria-label="Zoom out"><i class="fas fa-magnifying-glass-minus"></i></button>
            <button type="button" class="rv-level" id="rvLevel" title="Switch between actual size and fit (0)" aria-label="Current zoom">100%</button>
            <button type="button" class="rv-tool" id="rvIn" title="Zoom in (+)" aria-label="Zoom in"><i class="fas fa-magnifying-glass-plus"></i></button>
            <button type="button" class="rv-tool" id="rvFit" title="Fit the sheet (0)" aria-label="Fit the sheet"><i class="fas fa-expand"></i></button>
            <span class="rv-sep"></span>
            <a class="rv-btn rv-btn-print" id="rvPrint" href="#" target="_blank" rel="noopener"><i class="fas fa-print"></i> Print</a>
            <a class="rv-btn rv-btn-primary" id="rvDownload" href="#"><i class="fas fa-file-arrow-down"></i> Download PDF</a>
            <a class="rv-btn" id="rvTab" href="#" target="_blank" rel="noopener" title="Open in a new tab" aria-label="Open in a new tab"><i class="fas fa-up-right-from-square"></i></a>
            <span class="rv-hint">Ctrl + scroll or pinch to zoom &middot; drag to move</span>
        </div>

        <div class="rv-stage" id="rvStage">
            <div class="rv-scaler" id="rvScaler"><div class="rv-doc" id="rvDoc"></div></div>
            <div class="rv-status-box" id="rvMsg" hidden></div>
        </div>

        <div class="rv-foot" id="rvFoot"></div>
    </div>
</div>
`;

const RequestViewer = (() => {
    const MIN = 0.25, MAX = 4, STEP = 1.25;

    let el = null;
    let scale = 1, fitScale = 1, natural = { w: 0, h: 0 };
    let pinch = null, pan = null, tap = null, lastTap = 0, lastTouchAt = 0, token = 0;

    const loaded = () => natural.w > 0;
    const byId = id => document.getElementById(id);

    function init() {
        if (el) return;
        document.body.insertAdjacentHTML('beforeend', REQUEST_VIEWER_MARKUP);

        el = {
            root: byId('rvRoot'), dialog: document.querySelector('#rvRoot .rv-dialog'),
            title: byId('rvTitle'), sub: byId('rvSub'), status: byId('rvStatus'),
            stage: byId('rvStage'), scaler: byId('rvScaler'), doc: byId('rvDoc'), msg: byId('rvMsg'),
            zoomIn: byId('rvIn'), zoomOut: byId('rvOut'), level: byId('rvLevel'), fit: byId('rvFit'),
            print: byId('rvPrint'), download: byId('rvDownload'), tab: byId('rvTab'),
            sep: document.querySelector('#rvRoot .rv-sep'), foot: byId('rvFoot')
        };

        el.root.querySelectorAll('[data-rv-close]').forEach(b => b.addEventListener('click', close));
        el.zoomIn.addEventListener('click', () => zoomBy(STEP));
        el.zoomOut.addEventListener('click', () => zoomBy(1 / STEP));
        el.fit.addEventListener('click', () => setScale(fitScale));
        // The percentage toggles between the sheet's real size and fitting the window.
        el.level.addEventListener('click', () => setScale(Math.abs(scale - 1) < 0.02 ? fitScale : 1));

        el.stage.addEventListener('wheel', onWheel, { passive: false });
        el.stage.addEventListener('dblclick', onDoubleClick);
        el.stage.addEventListener('pointerdown', onPointerDown);
        el.stage.addEventListener('touchstart', onTouchStart, { passive: false });
        el.stage.addEventListener('touchmove', onTouchMove, { passive: false });
        el.stage.addEventListener('touchend', onTouchEnd);
        el.stage.addEventListener('touchcancel', () => { pinch = null; tap = null; });

        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', () => {
            if (!loaded() || el.root.hidden) return;
            const wasFit = Math.abs(scale - fitScale) < 0.01;
            computeFit();
            setScale(wasFit ? fitScale : scale);
        });
    }

    async function open(opts) {
        init();
        const o = opts || {};
        if (!o.id) return;

        el.root.classList.toggle('rv-dark', o.theme === 'dark');
        el.title.textContent = o.title || 'Request';
        el.sub.textContent = o.subtitle || '';

        if (o.status && o.status.label) {
            el.status.textContent = o.status.label;
            el.status.style.background = (o.status.color || '#64748b') + '26';
            el.status.style.color = o.status.color || '#64748b';
        } else {
            el.status.textContent = '';
        }

        // A viewer without either file action keeps only the zoom cluster, so the
        // separator before them would hang at the end of the toolbar.
        const canPrint = o.allowPrint !== false;
        const canDownload = o.allowDownload !== false;
        el.print.hidden = !canPrint;
        el.download.hidden = !canDownload;
        el.sep.hidden = !canPrint && !canDownload;

        el.print.href = 'request_form_view.php?id=' + o.id + '&mode=print';
        el.download.href = 'download_request_form.php?id=' + o.id;
        el.tab.href = 'request_form_view.php?id=' + o.id;

        buildFooter(o.actions || []);

        el.root.hidden = false;
        document.body.classList.add('rv-open');
        requestAnimationFrame(() => {
            el.root.classList.add('is-open');
            el.dialog.focus();
        });

        el.doc.textContent = '';
        natural = { w: 0, h: 0 };
        el.scaler.style.width = el.scaler.style.height = '';
        message('fa-spinner fa-spin', 'Opening the form…', false);
        updateToolbar();

        const mine = ++token;
        try {
            const res = await fetch('request_form_view.php?id=' + o.id + '&mode=fragment', { credentials: 'same-origin' });
            const body = await res.text();
            if (mine !== token) return;
            if (!res.ok) throw new Error(body || res.statusText);

            // Server-rendered markup: every value was escaped in request_form_render.php.
            el.doc.innerHTML = body;
            el.msg.hidden = true;
            await settleImages();
            if (mine !== token) return;
            // Answers are fitted to their printed lines (request_form.js). This
            // runs untransformed, before measuring, so the sheet's size is final.
            el.doc.style.transform = 'none';
            if (typeof fitRequestSheet === 'function') fitRequestSheet(el.doc);
            measure();
        } catch (err) {
            if (mine !== token) return;
            el.doc.textContent = '';
            message('fa-triangle-exclamation', (err && err.message) || 'The form could not be loaded.', true);
            updateToolbar();
        }
    }

    function close() {
        if (!el || el.root.hidden) return;
        token++;                       // drops any load still in flight
        el.root.classList.remove('is-open');
        document.body.classList.remove('rv-open');
        setTimeout(() => {
            el.root.hidden = true;
            el.doc.textContent = '';
            natural = { w: 0, h: 0 };
        }, 220);
    }

    /** The logo decides the sheet's height, so measure only once it has settled. */
    function settleImages() {
        const pending = [...el.doc.querySelectorAll('img')].filter(img => !img.complete);
        if (!pending.length) return Promise.resolve();
        return Promise.all(pending.map(img => new Promise(done => {
            img.addEventListener('load', done, { once: true });
            img.addEventListener('error', done, { once: true });
        })));
    }

    function measure() {
        el.doc.style.transform = 'none';
        el.scaler.style.width = el.scaler.style.height = '';
        natural = { w: el.doc.offsetWidth, h: el.doc.offsetHeight };
        computeFit();
        setScale(fitScale);
        el.stage.scrollTop = 0;
        el.stage.scrollLeft = 0;
    }

    function computeFit() {
        if (!natural.w) { fitScale = 1; return; }
        const cs = getComputedStyle(el.stage);
        const avail = el.stage.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        fitScale = Math.min(1, Math.max(MIN, avail / natural.w));
    }

    function zoomBy(factor) { if (loaded()) setScale(scale * factor); }

    /** anchor = {x, y} in viewport coordinates; that point stays put while zooming. */
    function setScale(next, anchor) {
        if (!loaded()) return;
        next = Math.min(MAX, Math.max(MIN, next));

        const stageRect = el.stage.getBoundingClientRect();
        const ax = anchor ? anchor.x : stageRect.left + el.stage.clientWidth / 2;
        const ay = anchor ? anchor.y : stageRect.top + el.stage.clientHeight / 2;
        const before = el.scaler.getBoundingClientRect();
        const fx = before.width ? (ax - before.left) / before.width : 0.5;
        const fy = before.height ? (ay - before.top) / before.height : 0.5;

        scale = next;
        el.doc.style.transform = 'scale(' + scale + ')';
        el.scaler.style.width = Math.round(natural.w * scale) + 'px';
        el.scaler.style.height = Math.round(natural.h * scale) + 'px';

        const after = el.scaler.getBoundingClientRect();
        el.stage.scrollLeft += (after.left + fx * after.width) - ax;
        el.stage.scrollTop += (after.top + fy * after.height) - ay;
        updateToolbar();
    }

    function updateToolbar() {
        const on = loaded();
        el.level.textContent = on ? Math.round(scale * 100) + '%' : '—';
        el.level.disabled = !on;
        el.fit.disabled = !on;
        el.zoomIn.disabled = !on || scale >= MAX - 0.001;
        el.zoomOut.disabled = !on || scale <= MIN + 0.001;
        el.stage.classList.toggle('can-pan', on &&
            (el.stage.scrollWidth > el.stage.clientWidth + 1 || el.stage.scrollHeight > el.stage.clientHeight + 1));
    }

    function message(iconClass, text, isError) {
        el.msg.textContent = '';
        el.msg.classList.toggle('is-error', !!isError);
        const icon = document.createElement('i');
        icon.className = 'fas ' + iconClass;
        const line = document.createElement('div');
        line.textContent = text;            // never innerHTML: this can carry a server message
        el.msg.append(icon, line);
        el.msg.hidden = false;
    }

    /** Whatever the caller wants under the sheet, plus Close. */
    function buildFooter(actions) {
        el.foot.textContent = '';

        const close_ = document.createElement('button');
        close_.type = 'button';
        close_.className = 'rv-btn';
        close_.textContent = 'Close';
        close_.addEventListener('click', close);
        el.foot.appendChild(close_);

        actions.forEach(a => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'rv-btn ' + (a.className || '');
            if (a.icon) {
                const i = document.createElement('i');
                i.className = 'fas ' + a.icon;
                b.appendChild(i);
            }
            b.appendChild(document.createTextNode(a.label || ''));
            b.addEventListener('click', () => { close(); if (a.onClick) a.onClick(); });
            el.foot.appendChild(b);
        });
    }

    // ── Input ──
    function onKey(e) {
        if (!el || el.root.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); close(); return; }
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (/^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName || '')) return;
        if (e.key === '+' || e.key === '=') { e.preventDefault(); zoomBy(STEP); }
        else if (e.key === '-' || e.key === '_') { e.preventDefault(); zoomBy(1 / STEP); }
        else if (e.key === '0') { e.preventDefault(); setScale(fitScale); }
    }

    function onWheel(e) {
        // Plain wheel scrolls the sheet; Ctrl+wheel (and trackpad pinch) zooms.
        if (!loaded() || !e.ctrlKey) return;
        e.preventDefault();
        setScale(scale * Math.min(1.25, Math.max(0.8, Math.exp(-e.deltaY * 0.01))), { x: e.clientX, y: e.clientY });
    }

    function onDoubleClick(e) {
        if (!loaded() || Date.now() - lastTouchAt < 800) return;
        setScale(scale > fitScale * 1.05 ? fitScale : Math.max(1, fitScale * 2), { x: e.clientX, y: e.clientY });
    }

    function onPointerDown(e) {
        if (e.pointerType !== 'mouse' || e.button !== 0 || !el.stage.classList.contains('can-pan')) return;
        e.preventDefault();
        pan = { x: e.clientX, y: e.clientY, left: el.stage.scrollLeft, top: el.stage.scrollTop };
        el.stage.classList.add('panning');
        el.stage.setPointerCapture(e.pointerId);
        el.stage.addEventListener('pointermove', onPointerMove);
        el.stage.addEventListener('pointerup', endPan, { once: true });
        el.stage.addEventListener('pointercancel', endPan, { once: true });
    }
    function onPointerMove(e) {
        if (!pan) return;
        el.stage.scrollLeft = pan.left - (e.clientX - pan.x);
        el.stage.scrollTop = pan.top - (e.clientY - pan.y);
    }
    function endPan() {
        pan = null;
        el.stage.classList.remove('panning');
        el.stage.removeEventListener('pointermove', onPointerMove);
    }

    const touchDistance = t => Math.hypot(t[0].clientX - t[1].clientX, t[0].clientY - t[1].clientY);

    function onTouchStart(e) {
        lastTouchAt = Date.now();
        if (e.touches.length === 2 && loaded()) {
            e.preventDefault();
            pinch = { distance: touchDistance(e.touches), scale: scale };
            tap = null;
        } else if (e.touches.length === 1) {
            tap = { x: e.touches[0].clientX, y: e.touches[0].clientY };
        }
    }
    function onTouchMove(e) {
        if (pinch && e.touches.length === 2) {
            e.preventDefault();
            const mid = {
                x: (e.touches[0].clientX + e.touches[1].clientX) / 2,
                y: (e.touches[0].clientY + e.touches[1].clientY) / 2
            };
            setScale(pinch.scale * touchDistance(e.touches) / pinch.distance, mid);
        } else if (tap && e.touches.length === 1 &&
            Math.hypot(e.touches[0].clientX - tap.x, e.touches[0].clientY - tap.y) > 10) {
            tap = null; // a scroll, not a tap
        }
    }
    function onTouchEnd(e) {
        lastTouchAt = Date.now();
        if (pinch) { if (e.touches.length < 2) pinch = null; return; }
        if (!tap || e.touches.length !== 0 || !loaded()) return;
        const now = Date.now();
        if (now - lastTap < 300) {
            e.preventDefault();
            const t = e.changedTouches[0];
            setScale(scale > fitScale * 1.05 ? fitScale : Math.max(1, fitScale * 2), { x: t.clientX, y: t.clientY });
            lastTap = 0;
        } else {
            lastTap = now;
        }
        tap = null;
    }

    return { init, open, close };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', RequestViewer.init);
} else {
    RequestViewer.init();
}

function openRequestForm(opts) { RequestViewer.open(opts); }
function closeRequestForm() { RequestViewer.close(); }
