/*
 * Document viewer modal, used by checklist.php and my_document.php (link doc_viewer.css too).
 * Call openPreview(filePath, title); pass paths via data- attributes, never inline JS strings.
 */
const DOC_VIEWER_MARKUP = `
<div id="docViewer" class="doc-viewer" role="dialog" aria-modal="true" aria-labelledby="docViewerTitle" hidden>
    <div class="doc-viewer-backdrop" data-viewer-close></div>
    <div class="doc-viewer-dialog" tabindex="-1">
        <div class="doc-viewer-header">
            <div class="doc-viewer-heading">
                <span class="doc-viewer-icon"><i class="fas fa-file-image" id="docViewerIcon"></i></span>
                <div class="doc-viewer-titles">
                    <h3 id="docViewerTitle">Document Preview</h3>
                    <p id="docViewerMeta"></p>
                </div>
            </div>
            <div class="doc-viewer-header-actions">
                <a id="docViewerOpen" class="doc-viewer-icon-btn" href="#" target="_blank" rel="noopener" title="Open in new tab" aria-label="Open in new tab"><i class="fas fa-up-right-from-square"></i></a>
                <button type="button" class="doc-viewer-icon-btn doc-viewer-close" data-viewer-close title="Close (Esc)" aria-label="Close preview"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="doc-viewer-stage" id="docViewerStage">
            <div class="doc-viewer-content" id="docViewerContent"></div>
            <div class="doc-viewer-status" id="docViewerStatus" hidden></div>
        </div>
        <div class="doc-viewer-toolbar" role="toolbar" aria-label="Zoom controls">
            <button type="button" class="doc-viewer-tool" id="docZoomOut" title="Zoom out (-)" aria-label="Zoom out"><i class="fas fa-magnifying-glass-minus"></i></button>
            <button type="button" class="doc-viewer-zoom-level" id="docZoomLevel" title="Fit to screen (0)" aria-label="Current zoom, click to fit">100%</button>
            <button type="button" class="doc-viewer-tool" id="docZoomIn" title="Zoom in (+)" aria-label="Zoom in"><i class="fas fa-magnifying-glass-plus"></i></button>
            <span class="doc-viewer-divider"></span>
            <button type="button" class="doc-viewer-tool" id="docZoomFit" title="Fit to screen (0)" aria-label="Fit to screen"><i class="fas fa-expand"></i></button>
            <span class="doc-viewer-hint">Scroll or pinch to zoom · drag to move</span>
        </div>
    </div>
</div>
`;

// Images and PDFs (rendered with PDF.js so they also work on phones) share one zoom model:
// zoom 1 = fit to screen; the content is resized and the stage scrolls, so panning is native.
const DocViewer = (() => {
    const MIN_ZOOM = 0.5;
    const MAX_ZOOM = 5;
    const STEP = 1.25;
    const MAX_PDF_PAGES = 30;
    const PDFJS_BASE = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    const el = {};
    let state = null; // { type, token, zoom, baseWidth, aspect, pages, pdf }
    let lastFocus = null;
    let pdfjsPromise = null;
    let renderTimer = null;
    let renderGen = 0;
    let pan = null;
    let pinch = null;
    let tap = null;
    let lastTap = 0;
    let lastTouchAt = 0;

    const byId = id => document.getElementById(id);
    const zoomable = () => !!(state && state.baseWidth);

    function init() {
        if (el.root) return;
        if (!byId('docViewer')) document.body.insertAdjacentHTML('beforeend', DOC_VIEWER_MARKUP);
        Object.assign(el, {
            root: byId('docViewer'), stage: byId('docViewerStage'), content: byId('docViewerContent'),
            status: byId('docViewerStatus'), title: byId('docViewerTitle'), meta: byId('docViewerMeta'),
            icon: byId('docViewerIcon'), openLink: byId('docViewerOpen'),
            zoomIn: byId('docZoomIn'), zoomOut: byId('docZoomOut'), zoomLevel: byId('docZoomLevel'), zoomFit: byId('docZoomFit')
        });
        el.root.querySelectorAll('[data-viewer-close]').forEach(btn => btn.addEventListener('click', () => close()));
        el.zoomIn.addEventListener('click', () => zoomBy(STEP));
        el.zoomOut.addEventListener('click', () => zoomBy(1 / STEP));
        el.zoomLevel.addEventListener('click', () => setZoom(1));
        el.zoomFit.addEventListener('click', () => setZoom(1));
        el.stage.addEventListener('wheel', onWheel, { passive: false });
        el.stage.addEventListener('dblclick', onDoubleClick);
        el.stage.addEventListener('pointerdown', onPointerDown);
        el.stage.addEventListener('touchstart', onTouchStart, { passive: false });
        el.stage.addEventListener('touchmove', onTouchMove, { passive: false });
        el.stage.addEventListener('touchend', onTouchEnd);
        el.stage.addEventListener('touchcancel', () => { pinch = null; tap = null; });
        document.addEventListener('keydown', onKey);
        window.addEventListener('resize', () => {
            if (!zoomable()) return;
            computeBaseWidth();
            applyZoom();
            scheduleRender();
        });
    }

    // ── Open / close ──
    function open(filePath, docName) {
        if (!el.root) return;
        close(true);
        const ext = (filePath.split('.').pop() || '').toLowerCase();
        const type = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) ? 'image' : (ext === 'pdf' ? 'pdf' : 'other');
        const url = filePath.split('/').map(encodeURIComponent).join('/');
        const token = {};
        state = { type, token, zoom: 1, baseWidth: 0, aspect: 1, pages: [], pdf: null };

        lastFocus = document.activeElement;
        el.title.textContent = docName || 'Document Preview';
        el.meta.textContent = filePath.split('/').pop().replace(/^\d+_/, '');
        el.icon.className = 'fas ' + (type === 'pdf' ? 'fa-file-pdf' : type === 'image' ? 'fa-file-image' : 'fa-file');
        el.openLink.href = url;
        el.content.className = 'doc-viewer-content';
        el.content.style.width = '';
        el.content.innerHTML = '';
        showStatus('<i class="fas fa-spinner fa-spin"></i><span>Loading document...</span>');
        updateToolbar();

        el.root.hidden = false;
        document.body.classList.add('viewer-open');
        requestAnimationFrame(() => el.root.classList.add('open'));
        el.stage.scrollTop = 0;
        el.stage.scrollLeft = 0;
        el.stage.classList.remove('frame-mode', 'can-pan');
        el.root.querySelector('.doc-viewer-dialog').focus({ preventScroll: true });

        if (type === 'image') loadImage(url, token);
        else if (type === 'pdf') loadPdf(url, token);
        else showFrame(url, token);
    }

    function close(immediate = false) {
        if (!el.root || el.root.hidden) return;
        if (state && state.pdf) state.pdf.destroy();
        state = null;
        renderGen++;
        clearTimeout(renderTimer);
        el.root.classList.remove('open');
        document.body.classList.remove('viewer-open');
        const finish = () => { el.root.hidden = true; el.content.innerHTML = ''; hideStatus(); };
        if (immediate) finish();
        else setTimeout(() => { if (!el.root.classList.contains('open')) finish(); }, 220);
        if (!immediate && lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
    }

    // ── Loading ──
    function loadImage(url, token) {
        const img = new Image();
        img.alt = el.title.textContent;
        img.draggable = false;
        img.onload = () => {
            if (!state || state.token !== token) return;
            state.aspect = img.naturalWidth / img.naturalHeight;
            el.meta.textContent += ` · ${img.naturalWidth} × ${img.naturalHeight} px`;
            el.content.appendChild(img);
            hideStatus();
            computeBaseWidth();
            applyZoom();
        };
        img.onerror = () => { if (state && state.token === token) showError('This image could not be loaded.'); };
        img.src = url;
    }

    function loadPdfJs() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        if (!pdfjsPromise) {
            pdfjsPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = PDFJS_BASE + 'pdf.min.js';
                script.onload = () => {
                    window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js';
                    resolve(window.pdfjsLib);
                };
                script.onerror = () => { pdfjsPromise = null; reject(new Error('PDF.js failed to load')); };
                document.head.appendChild(script);
            });
        }
        return pdfjsPromise;
    }

    async function loadPdf(url, token) {
        try {
            const pdfjsLib = await loadPdfJs();
            // PDF.js resolves relative URLs against location.href, so resolve it the way the page does
            const pdf = await pdfjsLib.getDocument(new URL(url, document.baseURI).href).promise;
            if (!state || state.token !== token) { pdf.destroy(); return; }
            state.pdf = pdf;

            const count = Math.min(pdf.numPages, MAX_PDF_PAGES);
            for (let n = 1; n <= count; n++) {
                const page = await pdf.getPage(n);
                if (!state || state.token !== token) return;
                const size = page.getViewport({ scale: 1 });
                const canvas = document.createElement('canvas');
                canvas.style.aspectRatio = `${size.width} / ${size.height}`;
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', `Page ${n} of ${pdf.numPages}`);
                el.content.appendChild(canvas);
                state.pages.push({ page, canvas, renderedWidth: 0, task: null });
            }

            el.meta.textContent += ` · ${pdf.numPages} page${pdf.numPages > 1 ? 's' : ''}` +
                (pdf.numPages > count ? ` (showing first ${count})` : '');
            hideStatus();
            computeBaseWidth();
            applyZoom();
            renderPages();
        } catch (err) {
            console.error('[DocViewer] PDF preview failed:', err);
            if (state && state.token === token) showFrame(url, token);
        }
    }

    // Fallback for files PDF.js/images can't handle: the browser's own viewer (no custom zoom)
    function showFrame(url, token) {
        if (!state || state.token !== token) return;
        state.baseWidth = 0;
        hideStatus();
        el.content.innerHTML = '';
        el.content.classList.add('is-frame');
        el.stage.classList.add('frame-mode');
        const frame = document.createElement('iframe');
        frame.src = url;
        frame.title = el.title.textContent;
        el.content.appendChild(frame);
        el.meta.textContent += ' · If the preview stays blank, use "Open in new tab"';
        updateToolbar();
    }

    function showStatus(html) { el.status.innerHTML = html; el.status.hidden = false; }
    function hideStatus() { el.status.hidden = true; el.status.innerHTML = ''; }
    function showError(message) {
        state.baseWidth = 0;
        showStatus('<i class="fas fa-triangle-exclamation"></i><span></span>' +
            '<a class="doc-viewer-status-link" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square"></i> Open in new tab</a>');
        el.status.querySelector('span').textContent = message;
        el.status.querySelector('a').href = el.openLink.href;
        updateToolbar();
    }

    // ── Zoom ──
    function computeBaseWidth() {
        const cs = getComputedStyle(el.content);
        const availW = el.stage.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        const availH = el.stage.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
        state.baseWidth = Math.max(50, state.type === 'image' ? Math.min(availW, availH * state.aspect) : availW);
    }

    function applyZoom() {
        el.content.style.width = Math.round(state.baseWidth * state.zoom) + 'px';
        updateToolbar();
    }

    function zoomBy(factor) {
        if (zoomable()) setZoom(state.zoom * factor);
    }

    // anchor = {x, y} in viewport coordinates; the point under it stays put while zooming
    function setZoom(zoom, anchor) {
        if (!zoomable()) return;
        zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom));
        if (Math.abs(zoom - 1) < 0.03) zoom = 1;
        const stageRect = el.stage.getBoundingClientRect();
        const ax = anchor ? anchor.x : stageRect.left + el.stage.clientWidth / 2;
        const ay = anchor ? anchor.y : stageRect.top + el.stage.clientHeight / 2;
        const before = el.content.getBoundingClientRect();
        const fx = before.width ? (ax - before.left) / before.width : 0.5;
        const fy = before.height ? (ay - before.top) / before.height : 0.5;

        state.zoom = zoom;
        applyZoom();

        const after = el.content.getBoundingClientRect();
        el.stage.scrollLeft += (after.left + fx * after.width) - ax;
        el.stage.scrollTop += (after.top + fy * after.height) - ay;
        updateToolbar();
        scheduleRender();
    }

    function updateToolbar() {
        const on = zoomable();
        el.zoomLevel.textContent = on ? Math.round(state.zoom * 100) + '%' : '—';
        el.zoomIn.disabled = !on || state.zoom >= MAX_ZOOM - 0.001;
        el.zoomOut.disabled = !on || state.zoom <= MIN_ZOOM + 0.001;
        el.zoomLevel.disabled = !on;
        el.zoomFit.disabled = !on;
        el.stage.classList.toggle('can-pan', on &&
            (el.stage.scrollWidth > el.stage.clientWidth + 1 || el.stage.scrollHeight > el.stage.clientHeight + 1));
    }

    // Re-render PDF pages at the current zoom so text stays sharp
    function scheduleRender() {
        if (!state || state.type !== 'pdf') return;
        clearTimeout(renderTimer);
        renderTimer = setTimeout(renderPages, 200);
    }

    async function renderPages() {
        if (!state || state.type !== 'pdf' || !state.baseWidth) return;
        const gen = ++renderGen;
        const token = state.token;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        const target = Math.min(Math.round(state.baseWidth * state.zoom * dpr), 3000);

        for (const p of state.pages) {
            if (gen !== renderGen || !state || state.token !== token) return;
            if (p.renderedWidth >= target * 0.95 && p.renderedWidth <= target * 2) continue;
            const viewport = p.page.getViewport({ scale: target / p.page.getViewport({ scale: 1 }).width });
            const buffer = document.createElement('canvas');
            buffer.width = Math.floor(viewport.width);
            buffer.height = Math.floor(viewport.height);
            p.task = p.page.render({ canvasContext: buffer.getContext('2d'), viewport });
            try {
                await p.task.promise;
            } catch (err) {
                continue;
            } finally {
                p.task = null;
            }
            if (gen !== renderGen || !state || state.token !== token) return;
            p.canvas.width = buffer.width;
            p.canvas.height = buffer.height;
            p.canvas.getContext('2d').drawImage(buffer, 0, 0);
            p.renderedWidth = buffer.width;
        }
    }

    // ── Input ──
    function onKey(e) {
        if (!state || el.root.hidden) return;
        const plain = !e.ctrlKey && !e.metaKey && !e.altKey;
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (plain && (e.key === '+' || e.key === '=')) { e.preventDefault(); zoomBy(STEP); }
        else if (plain && (e.key === '-' || e.key === '_')) { e.preventDefault(); zoomBy(1 / STEP); }
        else if (plain && e.key === '0') { e.preventDefault(); setZoom(1); }
        else if (e.key === 'Tab') {
            const items = [...el.root.querySelectorAll('a[href], button:not([disabled])')];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    }

    function onWheel(e) {
        if (!zoomable()) return;
        // PDFs scroll through pages with the wheel; Ctrl+wheel (or trackpad pinch) zooms
        if (state.type === 'pdf' && !e.ctrlKey) return;
        e.preventDefault();
        const factor = Math.min(1.25, Math.max(0.8, Math.exp(-e.deltaY * (e.ctrlKey ? 0.01 : 0.002))));
        setZoom(state.zoom * factor, { x: e.clientX, y: e.clientY });
    }

    function onDoubleClick(e) {
        if (!zoomable() || Date.now() - lastTouchAt < 800) return;
        setZoom(state.zoom > 1.05 ? 1 : 2.5, { x: e.clientX, y: e.clientY });
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
        if (e.touches.length === 2 && zoomable()) {
            e.preventDefault();
            pinch = { distance: touchDistance(e.touches), zoom: state.zoom };
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
            setZoom(pinch.zoom * touchDistance(e.touches) / pinch.distance, mid);
        } else if (tap && e.touches.length === 1 &&
            Math.hypot(e.touches[0].clientX - tap.x, e.touches[0].clientY - tap.y) > 10) {
            tap = null; // it's a scroll, not a tap
        }
    }

    function onTouchEnd(e) {
        lastTouchAt = Date.now();
        if (pinch) {
            if (e.touches.length < 2) pinch = null;
            return;
        }
        if (!tap || e.touches.length !== 0 || !zoomable()) return;
        const now = Date.now();
        if (now - lastTap < 300) {
            e.preventDefault();
            const t = e.changedTouches[0];
            setZoom(state.zoom > 1.05 ? 1 : 2.5, { x: t.clientX, y: t.clientY });
            lastTap = 0;
        } else {
            lastTap = now;
        }
        tap = null;
    }

    return { init, open, close };
})();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', DocViewer.init);
} else {
    DocViewer.init();
}

function openPreview(filePath, docName) {
    DocViewer.open(filePath, docName);
}

function closePreview() {
    DocViewer.close();
}
