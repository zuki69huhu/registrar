/*
 * Fits every answer on a filed request's sheet to the printed line it sits on.
 *
 * A short blank on a paper form gets smaller handwriting — it is never cut
 * off, and it never runs past the line. The same happens here: each answer
 * starts at the sheet's own size and is stepped down until it fits its line
 * (or, for the few blanks the original prints two lines for, its two lines).
 * Only an answer that still cannot fit at the smallest legible size is let
 * wrap, so nothing the student wrote is ever hidden.
 *
 * download_request_form.php does the same for the PDF (RequestFormPdf::row()),
 * so the screen, the browser print-out and the download agree.
 *
 * Call fitRequestSheet(root) once the sheet is in the page and laid out:
 * request_viewer.js does it after fetching, request_form_view.php on load.
 */
function fitRequestSheet(root) {
    if (!root) return;
    const MIN = 7;      // px — about 5.25pt, still legible on paper
    const STEP = 0.5;

    const oneLine = root.querySelectorAll(
        '.rf-field:not(.rf-wrap):not(.rf-2line) .rf-fill, ' +
        '.rf-course-value, .rf-signed-name, .rf-addressee .rf-fill'
    );
    oneLine.forEach(el => {
        if (!el.textContent.trim()) return;
        let size = parseFloat(getComputedStyle(el).fontSize) || 11;
        while (el.scrollWidth > el.clientWidth + 1 && size > MIN) {
            size = Math.max(MIN, size - STEP);
            el.style.fontSize = size + 'px';
        }
        if (el.scrollWidth > el.clientWidth + 1) letItWrap(el);
    });

    // The blanks the original prints two lines for (COLLEGE on the graduation form).
    root.querySelectorAll('.rf-field.rf-2line .rf-fill').forEach(el => {
        if (!el.textContent.trim()) return;
        const cs = getComputedStyle(el);
        let size = parseFloat(cs.fontSize) || 11;
        const lineHeight = () => parseFloat(getComputedStyle(el).lineHeight) || size * 1.32;
        while (el.scrollHeight > lineHeight() * 2 + 2 && size > MIN) {
            size = Math.max(MIN, size - STEP);
            el.style.fontSize = size + 'px';
        }
    });
}

/** The last resort: show every word rather than hide any. */
function letItWrap(el) {
    el.style.whiteSpace = 'normal';
    el.style.overflow = 'visible';
    el.style.overflowWrap = 'anywhere';
}
