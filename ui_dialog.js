/*
 * The portal's confirm/alert box, in place of the browser's own grey dialog.
 *
 * Like doc_viewer.js and request_viewer.js it injects its own markup, so a page
 * needs only ui_dialog.css, this script, and a call:
 *
 *   uiConfirm({ message, title, confirmLabel, cancelLabel, tone })  -> Promise<boolean>
 *   uiAlert({ message, title, confirmLabel, tone })                 -> Promise<void>
 *
 * `tone` is 'default' | 'danger' | 'success' | 'info' and only picks the icon
 * and the colour of the confirming button.
 *
 * It also upgrades what is already on the page: any inline
 * onsubmit="return confirm('...')" or onclick="... confirm('...') ..." is taken
 * over on load, so the pages keep their markup and still get this box. An
 * upgraded onclick still runs its original code — the handler is re-run with
 * window.confirm forced to true — so nothing else about it changes.
 *
 * The native confirm() is synchronous and this one cannot be, which is why the
 * inline attributes are rewritten rather than window.confirm being replaced.
 */

const UI_DIALOG_MARKUP = `
<div class="uid" id="uidRoot" role="dialog" aria-modal="true" aria-labelledby="uidTitle" aria-describedby="uidMessage" hidden>
    <div class="uid-backdrop" data-uid-dismiss></div>
    <div class="uid-box" tabindex="-1">
        <div class="uid-icon" id="uidIcon"><i class="fas fa-circle-question"></i></div>
        <h3 class="uid-title" id="uidTitle"></h3>
        <p class="uid-message" id="uidMessage"></p>
        <div class="uid-actions">
            <button type="button" class="uid-btn uid-cancel" id="uidCancel">Cancel</button>
            <button type="button" class="uid-btn uid-ok" id="uidOk">OK</button>
        </div>
    </div>
</div>
`;

const UiDialog = (() => {
    const ICONS = {
        default: 'fa-circle-question',
        danger:  'fa-triangle-exclamation',
        success: 'fa-circle-check',
        info:    'fa-circle-info'
    };

    let el = null;
    let settle = null;          // resolves the promise of the open dialog
    let lastFocus = null;

    const byId = id => document.getElementById(id);

    function init() {
        if (el) return;
        document.body.insertAdjacentHTML('beforeend', UI_DIALOG_MARKUP);

        el = {
            root: byId('uidRoot'), box: document.querySelector('#uidRoot .uid-box'),
            icon: byId('uidIcon'), title: byId('uidTitle'), message: byId('uidMessage'),
            cancel: byId('uidCancel'), ok: byId('uidOk')
        };

        el.ok.addEventListener('click', () => close(true));
        el.cancel.addEventListener('click', () => close(false));
        el.root.querySelectorAll('[data-uid-dismiss]').forEach(n => n.addEventListener('click', () => close(false)));

        document.addEventListener('keydown', e => {
            if (el.root.hidden) return;
            if (e.key === 'Escape') { e.preventDefault(); close(false); }
            // Enter confirms, unless the focus is already on Cancel.
            if (e.key === 'Enter' && document.activeElement !== el.cancel) { e.preventDefault(); close(true); }
        });
    }

    /*
     * Light box on a light page, dark box on the registrar's dark one. The page
     * itself is asked, so a page that changes its own colours stays matched.
     */
    function prefersDark() {
        // The <body>, then the page behind it: a transparent body says nothing
        // about the colour, and reading it as black would darken every dialog.
        for (const node of [document.body, document.documentElement]) {
            const m = (getComputedStyle(node).backgroundColor || '').match(/rgba?\(([^)]+)\)/);
            if (!m) continue;
            const parts = m[1].split(',').map(v => parseFloat(v));
            if (parts.length > 3 && parts[3] === 0) continue;      // fully transparent
            return (0.299 * parts[0] + 0.587 * parts[1] + 0.114 * parts[2]) < 128;
        }
        return false;
    }

    function open(opts, withCancel) {
        init();
        const o = opts || {};

        // Only one of these at a time: a second call answers the first as cancelled.
        if (settle) close(false);

        const tone = ICONS[o.tone] ? o.tone : 'default';
        el.root.className = 'uid uid-' + tone + (prefersDark() ? ' uid-dark' : '') + (withCancel ? '' : ' uid-single');
        el.icon.innerHTML = '<i class="fas ' + ICONS[tone] + '"></i>';
        el.title.textContent = o.title || (withCancel ? 'Please confirm' : 'Notice');
        el.message.textContent = o.message || '';
        el.message.hidden = !o.message;
        el.ok.textContent = o.confirmLabel || (withCancel ? 'Yes, continue' : 'OK');
        el.cancel.textContent = o.cancelLabel || 'Cancel';
        el.cancel.hidden = !withCancel;

        lastFocus = document.activeElement;
        el.root.hidden = false;
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => {
            el.root.classList.add('is-open');
            el.ok.focus();
        });

        return new Promise(resolve => { settle = resolve; });
    }

    function close(answer) {
        if (!el || el.root.hidden) return;
        const done = settle;
        settle = null;

        el.root.classList.remove('is-open');
        document.body.style.overflow = '';
        setTimeout(() => { if (!settle) el.root.hidden = true; }, 160);

        if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
        lastFocus = null;
        if (done) done(answer);
    }

    return {
        confirm: opts => open(typeof opts === 'string' ? { message: opts } : opts, true),
        alert:   opts => open(typeof opts === 'string' ? { message: opts } : opts, false)
    };
})();

function uiConfirm(opts) { return UiDialog.confirm(opts); }
function uiAlert(opts)   { return UiDialog.alert(opts); }

/* ── Taking over the inline confirm() attributes already on the pages ──────── */

/** The message out of `confirm('…')`, with its escapes undone. */
function uiDialogMessage(source) {
    const m = String(source).match(/confirm\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1/);
    if (!m) return 'Are you sure you want to continue?';
    return m[2].replace(/\\(['"`\\])/g, '$1').replace(/\\n/g, ' ');
}

/** Words that mean the action cannot simply be undone get the red button. */
function uiDialogTone(message) {
    return /\b(delete|remove|archive|cancel|reject|discard)/i.test(message) ? 'danger' : 'default';
}

function uiDialogUpgrade(root) {
    const scope = root || document;

    scope.querySelectorAll('form[onsubmit*="confirm("]').forEach(form => {
        const message = uiDialogMessage(form.getAttribute('onsubmit'));
        form.removeAttribute('onsubmit');
        form.addEventListener('submit', e => {
            e.preventDefault();
            // Let the browser's own field validation speak first.
            if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
            uiConfirm({ message: message, tone: uiDialogTone(message) }).then(ok => {
                if (ok) form.submit();   // submit() fires no event, so this runs once
            });
        });
    });

    scope.querySelectorAll('[onclick*="confirm("]').forEach(node => {
        const source = node.getAttribute('onclick');
        const message = uiDialogMessage(source);
        const original = new Function('event', source);
        node.removeAttribute('onclick');
        node.addEventListener('click', e => {
            e.preventDefault();
            uiConfirm({ message: message, tone: uiDialogTone(message) }).then(ok => {
                if (!ok) return;
                // The handler keeps whatever else it did; its confirm() is already answered.
                const native = window.confirm;
                window.confirm = () => true;
                try { original.call(node, e); } finally { window.confirm = native; }
            });
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => uiDialogUpgrade());
} else {
    uiDialogUpgrade();
}
