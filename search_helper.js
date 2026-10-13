/*
 * search_helper.js — the one matcher every table search box uses.
 *
 * What makes a search "accurate" here:
 *   - every word of the query must appear somewhere in the row, in any order,
 *     so "ruales john" finds "Ruales, John Philip A.";
 *   - case, accents and punctuation are ignored ("penablanca" finds
 *     "Peñablanca"), and a student ID matches with or without its dash
 *     ("2023-10250", "202310250", "10250");
 *   - only what a row SAYS is searched: buttons, dropdowns and form controls are
 *     skipped, so typing "approve" or "view" no longer matches every row that
 *     happens to carry that button. A row may instead supply its own text in
 *     data-search, which is then used as-is;
 *   - it runs on `input`, so pasting, autofill and the clear (x) button refilter
 *     too, not only key presses; Escape clears the box.
 *
 * It also reports the result: a small "3 / 12" count inside the search box and a
 * "No results" row in any table the search has emptied.
 *
 * Usage (see the pages that load it):
 *   const run = SearchKit.bind(input, applyFilters);   // wire the box
 *   SearchKit.matches(row, input.value)                // inside the row loop
 *   SearchKit.report(input, [{table, shown, total}], filtersActive)
 */
(function () {
    'use strict';

    // Anything that is an action rather than content.
    const IGNORE = 'button, select, option, textarea, input, script, style, template, '
                 + '[class*="btn"], [data-search-ignore], .modal, .dropdown-menu';

    /** Lower-case, accents stripped, punctuation turned into spaces. */
    function normalize(text) {
        return String(text == null ? '' : text)
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    }

    function tokens(query) {
        const n = normalize(query);
        return n ? n.split(' ') : [];
    }

    // Each row's searchable text, re-read only when the row's text changes.
    const cache = new WeakMap();

    function readRow(row) {
        if (row.dataset && row.dataset.search) return row.dataset.search;
        const parts = [];
        const walker = document.createTreeWalker(row, NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                const el = node.parentElement;
                return el && el.closest(IGNORE) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) parts.push(walker.currentNode.nodeValue);
        return parts.join(' ');
    }

    function rowIndex(row) {
        const sig = (row.dataset && row.dataset.search) || row.textContent;
        const hit = cache.get(row);
        if (hit && hit.sig === sig) return hit;
        const norm = normalize(readRow(row));
        const entry = { sig, norm, compact: norm.replace(/ /g, '') };
        cache.set(row, entry);
        return entry;
    }

    /** True when every word of the query appears in the row. */
    function matches(row, query) {
        const words = Array.isArray(query) ? query : tokens(query);
        if (!words.length) return true;
        const idx = rowIndex(row);
        return words.every(w => idx.norm.includes(w) || idx.compact.includes(w));
    }

    /** Placeholder rows ("No documents yet") and our own no-result row. */
    function isPlaceholder(row) {
        return row.classList.contains('sk-empty')
            || (row.cells && row.cells.length === 1 && row.cells[0].hasAttribute('colspan'));
    }

    // ─── Reporting ───────────────────────────────────────────────────────────

    function injectStyles() {
        if (document.getElementById('sk-styles')) return;
        const css = `
            .sk-count { flex-shrink: 0; margin-left: auto; padding: 2px 9px; border-radius: 999px;
                font-weight: 600; font-size: 0.72rem; line-height: 1.5; white-space: nowrap;
                background: rgba(201,162,39,0.16); color: #e6c65a; }
            .sk-count.is-zero { background: rgba(239,68,68,0.16); color: #f87171; }
            .sk-count[hidden] { display: none; }
            body.student-mode .sk-count { background: #fef3c7; color: #92400e; }
            body.student-mode .sk-count.is-zero { background: #fee2e2; color: #b91c1c; }
            .sk-empty td { text-align: center !important; padding: 28px 12px !important;
                color: #94a3b8; font-size: 0.88rem;
                white-space: normal !important; overflow-wrap: anywhere; }
            .sk-empty td::before { content: none !important; }
            .sk-empty .sk-empty-q { color: inherit; font-weight: 700; word-break: break-word; }
            .sk-input::-webkit-search-cancel-button { cursor: pointer; filter: invert(0.6); }
            body.student-mode .sk-input::-webkit-search-cancel-button { filter: none; }
            .sk-input { min-width: 0; text-overflow: ellipsis; }
            @media (max-width: 768px) {
                /* 16px stops iOS from zooming the page when the box is tapped. */
                .sk-input { font-size: 16px !important; }
                /* Card layouts: the no-result row is one full-width card. */
                .sk-empty, .sk-empty td { display: block !important; width: 100% !important; }
            }`;
        const style = document.createElement('style');
        style.id = 'sk-styles';
        style.textContent = css;
        document.head.appendChild(style);
    }

    function countBadge(input) {
        let badge = input._skBadge;
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'sk-count';
            badge.setAttribute('aria-live', 'polite');
            badge.hidden = true;
            input.insertAdjacentElement('afterend', badge);
            input._skBadge = badge;
        }
        return badge;
    }

    function setEmptyRow(table, show, query) {
        if (!table) return;
        const body = table.tBodies[0] || table;
        let row = body.querySelector(':scope > tr.sk-empty');
        if (!show) { if (row) row.remove(); return; }
        const headRow = table.tHead && table.tHead.rows[0];
        const cols = headRow ? Array.from(headRow.cells).reduce((n, c) => n + (c.colSpan || 1), 0) : 1;
        if (!row) {
            row = document.createElement('tr');
            row.className = 'sk-empty';
            row.innerHTML = '<td></td>';
            body.appendChild(row);
        }
        const td = row.cells[0];
        td.colSpan = cols;
        td.textContent = '';
        const q = String(query || '').trim();
        if (q) {
            // Plain ASCII only: Apache serves .js without a charset, so
            // non-ASCII characters in strings could be misread.
            td.append('No results for "');
            const b = document.createElement('span');
            b.className = 'sk-empty-q';
            b.textContent = q;
            td.append(b, '". Check the spelling, or search by surname or student ID.');
        } else {
            td.textContent = 'No records match the selected filters.';
        }
    }

    /**
     * entries: [{table, shown, total}] for each table the box searches.
     * active:  whether any filter besides the box is set (the count shows then too).
     */
    function report(input, entries, active) {
        injectStyles();
        const query = input ? input.value : '';
        const searching = tokens(query).length > 0;
        let shown = 0, total = 0;
        (entries || []).forEach(e => {
            shown += e.shown; total += e.total;
            setEmptyRow(e.table, (searching || active) && e.total > 0 && e.shown === 0, query);
        });
        if (!input) return;
        const badge = countBadge(input);
        if ((searching || active) && total > 0) {
            badge.hidden = false;
            badge.textContent = `${shown} / ${total}`;
            badge.title = `${shown} of ${total} shown`;
            badge.classList.toggle('is-zero', shown === 0);
        } else {
            badge.hidden = true;
        }
    }

    /**
     * Wires a search box to fn: refilters on every change of its value, and
     * Escape clears it. Returns fn so the caller can re-run it (e.g. after
     * switching sections or rendering new rows).
     */
    function bind(input, fn) {
        if (!input) return fn;
        injectStyles();
        input.classList.add('sk-input');
        if (input.type === 'text') input.type = 'search';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        if (!input.getAttribute('aria-label')) input.setAttribute('aria-label', input.placeholder || 'Search');
        input.addEventListener('input', () => fn());
        input.addEventListener('keydown', e => {
            if (e.key === 'Escape' && input.value) { input.value = ''; fn(); }
        });
        return fn;
    }

    /**
     * The whole job for a plain table: show the rows that match, hide the rest,
     * and report. extra(row) may add the page's own conditions.
     */
    function filterTables(input, tables, extra, active) {
        const words = tokens(input ? input.value : '');
        const entries = [];
        (tables || []).forEach(table => {
            if (!table) return;
            let shown = 0, total = 0;
            table.querySelectorAll('tbody tr').forEach(row => {
                if (isPlaceholder(row)) return;
                total++;
                const ok = matches(row, words) && (!extra || extra(row));
                row.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            entries.push({ table, shown, total });
        });
        report(input, entries, active);
        return entries;
    }

    window.SearchKit = { normalize, tokens, matches, isPlaceholder, report, bind, filterTables };
})();
