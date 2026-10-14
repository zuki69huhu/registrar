<?php
/**
 * Renders a filed request as the paper form it replaces — the same letterhead,
 * the same tables, the same boxes and the same ruled lines, in the same places,
 * with the student's answers written on those lines.
 *
 * The rule under a field is always drawn, filled or not, exactly where the
 * printed original has it: nothing of the blank form disappears just because a
 * student answered it. Only the ink changes. Pre-printed wording stays black;
 * whatever the student supplied is written in ink blue, so a registrar can see
 * at a glance what was filled in.
 *
 * This is what the registrar sees when they click View, what the Print button
 * sends to the printer, and what download_request_form.php mirrors as a PDF.
 * It produces markup only; assets/css/request_form.css styles it and request_form_view.php
 * serves it.
 *
 * Everything that came from a person goes through rfEsc() before it reaches the
 * page, so a student's own words can never become markup.
 */

require_once __DIR__ . '/request_config.php';
require_once __DIR__ . '/name_helper.php';

/** Short alias — this file escapes on every single interpolation. */
function rfEsc($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * One fill-in-the-blank of the paper form: the pre-printed label, then the rule
 * the student would have written on, carrying their answer.
 *
 * @param string $label   Pre-printed caption, '' when the rule stands alone
 * @param string $value   What the student wrote, '' leaves the rule empty
 * @param string $variant Extra class: 'rf-inline' for a rule inside a sentence,
 *                        'rf-wrap' for free text that may run to several lines.
 *                        Every other answer is fitted to its one printed line
 *                        by assets/js/request_form.js, never cut off.
 */
function rfField($label, $value, $variant = '') {
    $value = trim((string)$value);
    $classes = 'rf-field' . ($variant !== '' ? ' ' . $variant : '');
    $fill    = 'rf-fill' . ($value === '' ? ' is-blank' : '');

    return '<span class="' . $classes . '">'
         . ($label !== '' ? '<span class="rf-label">' . rfEsc($label) . '</span>' : '')
         . '<span class="' . $fill . '">' . ($value === '' ? '' : rfEsc($value)) . '</span>'
         . '</span>';
}

/** The tick in the REMARKS column: what the student said about each document. */
function rfRemark($done, $optional = false) {
    if ($done) {
        return '<span class="rf-yes"><span class="rf-check">&#10003;</span> Submitted</span>';
    }
    return $optional
        ? '<span class="rf-na">N/A</span>'
        : '<span class="rf-no">Not yet</span>';
}

/**
 * The sheet for whichever form was filed.
 *
 * @param array $request Row from student_requests joined with its student's users row
 * @param array $form    Form metadata from getRequestFormsForInstitution()
 * @return string
 */
function renderRequestFormDocument(array $request, array $form) {
    switch ($request['form_type'] ?? 'graduation') {
        case 'form137':
        case 'otr':
        case 'nstp':
            return renderLetterDocument($request, $form);
        default:
            return renderGraduationDocument($request, $form);
    }
}

/** Application for Graduation (Form 005) — two pages. */
function renderGraduationDocument(array $request, array $form) {
    $data = decodeRequestForm($request['form_data'] ?? '');
    $head = $form['letterhead'] ?? [];

    $status      = $request['status'] ?? 'pending';
    $meta        = requestStatusMeta($status);
    $ref         = '#' . str_pad((string)($request['id'] ?? 0), 5, '0', STR_PAD_LEFT);
    $is_transferee = !empty($data['is_transferee']);
    $ticked      = $data['requirements'] ?? [];
    $declared    = $data['declarations'] ?? [];

    $signed_name = trim((string)($data['signed_name'] ?? '')) ?: formatPersonNameRow($request);
    $signed_at   = !empty($data['signed_at']) ? date('F j, Y', strtotime($data['signed_at'])) : '';
    $filed_at    = !empty($request['submitted_at']) ? date('F j, Y', strtotime($request['submitted_at'])) : '';

    // Deadline line on the paper form: once a schedule exists, that is the date
    // the student is expected at the office, so it is the honest thing to write
    // on that rule. Without one the rule stays blank, as on the printed form.
    $deadline = !empty($request['claim_at']) ? date('F j, Y', strtotime($request['claim_at'])) : '';

    ob_start();
    ?>
    <article class="rf-doc" data-request="<?= rfEsc($request['id'] ?? '') ?>">

        <!-- ══ PAGE 1 ══ -->
        <section class="rf-page">
            <?= rfLetterhead($head, 'APPLICATION FOR GRADUATION') ?>

            <div class="rf-watermark-layer"><div class="rf-watermark rf-watermark-<?= rfEsc($status) ?>"><?= rfEsc(strtoupper($meta['label'])) ?></div></div>

            <div class="rf-intro">
                <div class="rf-intro-left">
                    <div class="rf-note">
                        <p><em>Note: Please write in <strong>CAPITAL LETTERS</strong> and use a ballpoint pen.</em></p>
                        <p><em>(Important: <strong>Print Complete Name</strong> as it should appear in your graduation records.)</em></p>
                    </div>
                    <div class="rf-course-line">
                        <div class="rf-course-value"><?= $data['course'] ?? '' ? rfEsc($data['course']) : '&nbsp;' ?></div>
                        <div class="rf-course-caption">(COURSE)</div>
                    </div>
                </div>
                <div class="rf-photo-box">Latest<br>2X2<br>photo</div>
            </div>

            <table class="rf-table rf-personal">
                <tr>
                    <td class="rf-cell rf-w36"><?= rfField('LAST NAME:',   $data['last_name'] ?? '') ?></td>
                    <td class="rf-cell rf-w30"><?= rfField('FIRST NAME:',  $data['first_name'] ?? '') ?></td>
                    <td class="rf-cell rf-w34"><?= rfField('MIDDLE NAME:', $data['middle_name'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="rf-cell rf-w48"><?= rfField('DATE OF BIRTH:', !empty($data['date_of_birth']) ? date('F j, Y', strtotime($data['date_of_birth'])) : '') ?></td>
                    <td class="rf-cell rf-w22"><?= rfField('SEX:',          $data['sex'] ?? '') ?></td>
                    <td class="rf-cell rf-w30"><?= rfField('CIVIL STATUS:', $data['civil_status'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="rf-cell" colspan="3"><?= rfField('PERMANENT ADDRESS:', $data['permanent_address'] ?? '') ?></td>
                </tr>
            </table>

            <table class="rf-table rf-education">
                <tr><td class="rf-cell rf-section-head" colspan="2">EDUCATION:</td></tr>
                <tr>
                    <td class="rf-cell"><?= rfField('ELEMENTARY:', $data['elementary'] ?? '') ?></td>
                    <td class="rf-cell rf-year"><?= rfField('YEAR GRADUATED:', $data['elementary_year'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="rf-cell"><?= rfField('JUNIOR HIGH SCHOOL:', $data['junior_high'] ?? '') ?></td>
                    <td class="rf-cell rf-year"><?= rfField('YEAR GRADUATED:', $data['junior_high_year'] ?? '') ?></td>
                </tr>
                <tr>
                    <td class="rf-cell"><?= rfField('SENIOR HIGH SCHOOL:', $data['senior_high'] ?? '') ?></td>
                    <td class="rf-cell rf-year"><?= rfField('YEAR GRADUATED:', $data['senior_high_year'] ?? '') ?></td>
                </tr>
                <tr class="rf-college-row">
                    <td class="rf-cell">
                        <span class="rf-field rf-2line">
                            <span class="rf-label">COLLEGE <em>(for transferees &amp; graduates of any course)</em>:</span>
                            <span class="rf-fill<?= trim((string)($data['college'] ?? '')) === '' ? ' is-blank' : '' ?>"><?= rfEsc($data['college'] ?? '') ?></span>
                        </span>
                    </td>
                    <td class="rf-cell rf-year"><?= rfField('YEAR ENDED:', $data['college_year'] ?? '') ?></td>
                </tr>
            </table>

            <p class="rf-deadline">
                All document requirements mentioned below must be submitted not later than
                <?= rfField('', $deadline, 'rf-inline') ?>
                at the Registrar&rsquo;s Office.
            </p>

            <?= rfRequirementsTable($ticked, $declared, $is_transferee) ?>
        </section>

        <!-- ══ PAGE 2 ══ -->
        <section class="rf-page">
            <?= rfLetterhead($head, 'APPLICATION FOR GRADUATION') ?>

            <div class="rf-watermark-layer"><div class="rf-watermark rf-watermark-<?= rfEsc($status) ?>"><?= rfEsc(strtoupper($meta['label'])) ?></div></div>

            <p class="rf-waiver"><strong><?= rfEsc($data['waiver_text'] ?? getGraduationWaiverText()) ?></strong></p>

            <div class="rf-signature-block">
                <div class="rf-signature">
                    <div class="rf-signed-name"><?= $signed_name !== '' ? rfEsc($signed_name) : '&nbsp;' ?></div>
                    <div class="rf-sig-caption">SIGNATURE OVER PRINTED NAME</div>
                    <div class="rf-sig-date"><?= rfField('Date:', $signed_at ?: $filed_at, 'rf-inline') ?></div>
                    <?php if (!empty($data['waiver_accepted'])): ?>
                        <div class="rf-esign">Accepted electronically through the student portal &middot; Reference <?= rfEsc($ref) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rf-approval">
                <div class="rf-approval-row">
                    <div class="rf-approval-label">Recommending Approval:</div>
                    <div class="rf-approval-slots">
                        <?php foreach (($form['recommending'] ?? []) as $slot): ?>
                            <div class="rf-slot">
                                <div class="rf-slot-rule">&nbsp;</div>
                                <div class="rf-slot-title"><?= rfEsc($slot) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="rf-approval-row">
                    <div class="rf-approval-label">Approved by:</div>
                    <div class="rf-approval-slots">
                        <?php foreach (($form['approvers'] ?? []) as $slot): ?>
                            <div class="rf-slot">
                                <div class="rf-slot-name"><?= rfEsc($slot['name']) ?></div>
                                <div class="rf-slot-title"><?= rfEsc($slot['title']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <?= rfRegistrarStub($request, $meta, $ref) ?>
        </section>
    </article>
    <?php
    return ob_get_clean();
}

/**
 * The school letterhead and the document-control box, repeated on every page.
 * With $title_below the form's title leaves the office line beside the box and
 * takes a line of its own under it, centred on the whole sheet (MCNP's Form 007).
 */
function rfLetterhead(array $head, $form_name, $underline = false, $title_below = false) {
    ob_start();
    ?>
    <?php $email2 = trim((string)($head['email2'] ?? '')); ?>
    <header class="rf-head<?= $email2 !== '' ? ' has-email2' : '' ?><?= $title_below ? ' has-title-below' : '' ?>">
        <div class="rf-rule"></div>
        <div class="rf-head-main">
            <img class="rf-logo" src="<?= rfEsc($head['logo'] ?? 'assets/img/logo.jpg') ?>" alt="">
            <div class="rf-head-text">
                <div class="rf-school" style="color: <?= rfEsc($head['color'] ?? '#c00000') ?>"><?= rfEsc($head['name'] ?? '') ?></div>
                <div class="rf-school-line"><?= rfEsc($head['address'] ?? '') ?></div>
                <div class="rf-school-line"><?= rfEsc($head['contact'] ?? '') ?></div>
                <?php /* A second address (MCNP's registrar@) sits under the first,
                         right-aligned to it, so the web line keeps its centring. */ ?>
                <div class="rf-school-line"><span class="rf-web-block">Website: <span class="rf-link"><?= rfEsc($head['website'] ?? '') ?></span><span class="rf-web-gap"></span>E-Mail Address: <span class="rf-link"><?= rfEsc($head['email'] ?? '') ?></span><?php if ($email2 !== ''): ?><br><span class="rf-link"><?= rfEsc($email2) ?></span><?php endif; ?></span></div>
            </div>
        </div>
        <div class="rf-rule"></div>

        <div class="rf-title-row<?= $title_below ? ' is-title-below' : '' ?>">
            <div class="rf-titles<?= empty($head['doc_no']) ? ' is-wide' : '' ?>">
                <div class="rf-office"><?= rfEsc($head['office'] ?? '') ?></div>
                <?php if (!$title_below): ?>
                <div class="rf-form-name<?= $underline ? ' is-underlined' : '' ?>"><?= rfEsc($form_name) ?></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($head['doc_no'])): ?>
            <table class="rf-control">
                <tr><td>Document No.<br><strong><?= rfEsc($head['doc_no']) ?></strong></td></tr>
                <tr><td>Effective Date: <strong><?= rfEsc($head['effective'] ?? '') ?></strong><br>Revision No. <strong><?= rfEsc($head['revision'] ?? '') ?></strong></td></tr>
            </table>
            <?php endif; ?>
        </div>
        <?php if ($title_below): ?>
        <div class="rf-form-name rf-form-name-below<?= $underline ? ' is-underlined' : '' ?>"><?= rfEsc($form_name) ?></div>
        <?php endif; ?>
    </header>
    <?php
    return ob_get_clean();
}

/**
 * Items 1-10 of the paper form. The numbering, the wording, the sub-items and
 * the empty SIGNATURE column all follow the printed form exactly; only the
 * REMARKS column carries what the student ticked.
 */
function rfRequirementsTable(array $ticked, array $declared, $is_transferee) {
    $req = [];
    foreach (getGraduationRequirements() as $item) $req[$item['key']] = $item;

    ob_start();
    ?>
    <table class="rf-table rf-docs">
        <thead>
            <tr>
                <th class="rf-col-doc">DOCUMENTS</th>
                <th class="rf-col-remark">REMARKS</th>
                <th class="rf-col-sig">SIGNATURE</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $plain = [
                1 => ['form137',        'Form 137/TOR from last school attended'],
                2 => ['psa_birth',      'PSA Birth Certificate (Photocopy)'],
                3 => ['photo_2x2',      'One 2x2 picture (white background w/ name tag)'],
                4 => ['psa_correction', 'PSA Approved Correction of Name/Birthdate, if necessary'],
            ];
            foreach ($plain as $n => [$key, $label]): ?>
                <tr>
                    <td class="rf-col-doc"><span class="rf-num"><?= $n ?>.</span> <?= rfEsc($label) ?></td>
                    <td class="rf-col-remark"><?= rfRemark(!empty($ticked[$key]), !empty($req[$key]['optional'])) ?></td>
                    <td class="rf-col-sig"></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td class="rf-col-doc"><span class="rf-num">5.</span> For Transferees:</td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>
            <?php
            $transferee = [
                'good_moral'       => 'Certificate of Good Moral Character<br>from school last attended',
                'honorable'        => 'Honorable Dismissal/Transfer Credential<br>from school last attended',
                'police_clearance' => 'Police Clearance/NBI Clearance (latest)',
            ];
            foreach ($transferee as $key => $label): ?>
                <tr>
                    <td class="rf-col-doc rf-sub">&#10070; <?= $label ?></td>
                    <td class="rf-col-remark"><?= $is_transferee ? rfRemark(!empty($ticked[$key])) : '<span class="rf-na">N/A</span>' ?></td>
                    <td class="rf-col-sig"></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td class="rf-col-doc"><span class="rf-num">6.</span> Completion Forms</td>
                <td class="rf-col-remark"><?= rfRemark(!empty($ticked['completion_forms'])) ?></td>
                <td class="rf-col-sig"></td>
            </tr>
            <tr>
                <td class="rf-col-doc"><span class="rf-num">7.</span> NSTP Grades/Diploma/Certificate of Completion</td>
                <td class="rf-col-remark"><?= rfRemark(!empty($ticked['nstp'])) ?></td>
                <td class="rf-col-sig"></td>
            </tr>

            <tr>
                <td class="rf-col-doc"><span class="rf-num">8.</span> In Addition:</td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>
            <?php
            $addition = [
                'no_failing_grades'     => 'I must not have failing grade/s and/or incomplete grade/s in any<br>academic or non-academic subject/s.',
                'department_clearance'  => 'I must obtain clearance from all department of the Institution<br>concerned.',
                'financial_obligations' => 'I must have paid all my financial obligation to the school.',
            ];
            foreach ($addition as $key => $label): ?>
                <tr>
                    <td class="rf-col-doc rf-sub">&#10070; <?= $label ?></td>
                    <td class="rf-col-remark"><?= !empty($declared[$key]) ? '<span class="rf-yes"><span class="rf-check">&#10003;</span> Yes</span>' : '<span class="rf-no">No</span>' ?></td>
                    <td class="rf-col-sig"></td>
                </tr>
            <?php endforeach; ?>

            <tr>
                <td class="rf-col-doc"><span class="rf-num">9.</span> Department requirement:</td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>
            <?php
            $department = [
                'practicum'              => 'Must have submitted all practicum requirements specified by the<br>practicum coordinator',
                'organization_clearance' => 'Must have been cleared from obligations in department affiliated<br>organization/s',
            ];
            foreach ($department as $key => $label): ?>
                <tr>
                    <td class="rf-col-doc rf-sub">&#10070; <?= $label ?></td>
                    <td class="rf-col-remark"><?= !empty($declared[$key]) ? '<span class="rf-yes"><span class="rf-check">&#10003;</span> Yes</span>' : '<span class="rf-no">No</span>' ?></td>
                    <td class="rf-col-sig"></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td class="rf-col-doc rf-sub">
                    <div class="rf-ruled-grid">
                        <span class="rf-blank"></span><span class="rf-blank"></span>
                        <span class="rf-blank"></span><span class="rf-blank"></span>
                        <span class="rf-blank"></span><span class="rf-blank"></span>
                        <span class="rf-blank"></span><span class="rf-blank"></span>
                    </div>
                </td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>
            <tr>
                <td class="rf-col-doc rf-sub">&#10070; Final grade/s 10 days before graduation</td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>

            <tr>
                <td class="rf-col-doc"><span class="rf-num">10.</span> OTHERS:</td>
                <td class="rf-col-remark"></td>
                <td class="rf-col-sig"></td>
            </tr>
            <tr class="rf-empty-row"><td class="rf-col-doc"></td><td class="rf-col-remark"></td><td class="rf-col-sig"></td></tr>
            <tr class="rf-empty-row"><td class="rf-col-doc"></td><td class="rf-col-remark"></td><td class="rf-col-sig"></td></tr>
            <tr class="rf-empty-row"><td class="rf-col-doc"></td><td class="rf-col-remark"></td><td class="rf-col-sig"></td></tr>
        </tbody>
    </table>
    <?php
    return ob_get_clean();
}

/**
 * The portal's own footer on the form: what the registrar decided, and the
 * claim stub the student presents at the counter. This is the part the paper
 * form never had, and the reason the printed copy is worth sending.
 */
function rfRegistrarStub(array $request, array $meta, $ref) {
    $status   = $request['status'] ?? 'pending';
    $reviewer = trim(($request['reviewer_first'] ?? '') . ' ' . ($request['reviewer_last'] ?? ''));
    $releaser = trim(($request['releaser_first'] ?? '') . ' ' . ($request['releaser_last'] ?? ''));
    $student  = formatPersonNameRow($request);

    ob_start();
    ?>
    <div class="rf-stub">
        <div class="rf-stub-head">
            <span>FOR THE REGISTRAR&rsquo;S OFFICE</span>
            <span class="rf-stub-status rf-status-<?= rfEsc($status) ?>"><?= rfEsc(strtoupper($meta['label'])) ?></span>
        </div>

        <table class="rf-table rf-stub-table">
            <tr>
                <td class="rf-cell"><?= rfField('REFERENCE NO.:', $ref) ?></td>
                <td class="rf-cell"><?= rfField('STUDENT NO.:', formatStudentId($request['student_id'] ?? '')) ?></td>
                <td class="rf-cell"><?= rfField('FILED:', !empty($request['submitted_at']) ? date('F j, Y', strtotime($request['submitted_at'])) : '') ?></td>
            </tr>
            <tr>
                <td class="rf-cell"><?= rfField('REVIEWED BY:', $reviewer) ?></td>
                <td class="rf-cell" colspan="2"><?= rfField('REVIEWED ON:', !empty($request['reviewed_at']) ? date('F j, Y g:i A', strtotime($request['reviewed_at'])) : '') ?></td>
            </tr>
            <tr>
                <td class="rf-cell"><?= rfField('CLAIM DATE:', !empty($request['claim_at']) ? date('l, F j, Y', strtotime($request['claim_at'])) : '') ?></td>
                <td class="rf-cell"><?= rfField('CLAIM TIME:', !empty($request['claim_at']) ? date('g:i A', strtotime($request['claim_at'])) : '') ?></td>
                <td class="rf-cell"><?= rfField('WHERE:', $request['claim_location'] ?? '') ?></td>
            </tr>
            <tr>
                <td class="rf-cell"><?= rfField('RELEASED ON:', !empty($request['claimed_at']) ? date('F j, Y g:i A', strtotime($request['claimed_at'])) : '') ?></td>
                <td class="rf-cell"><?= rfField('RECEIVED BY:', $request['claimed_by_name'] ?? '') ?></td>
                <td class="rf-cell"><?= rfField('RELEASED BY:', $releaser) ?></td>
            </tr>
            <?php if (!empty($request['student_note'])): ?>
                <tr><td class="rf-cell" colspan="3"><?= rfField('STUDENT\'S MESSAGE:', $request['student_note'], 'rf-wrap') ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($request['registrar_note'])): ?>
                <tr><td class="rf-cell" colspan="3"><?= rfField('REGISTRAR\'S NOTE:', $request['registrar_note'], 'rf-wrap') ?></td></tr>
            <?php endif; ?>
        </table>

        <div class="rf-stub-foot">
            Generated by the <?= rfEsc($request['institution'] ?? '') ?> document portal for <?= rfEsc($student) ?>
            on <?= rfEsc(date('F j, Y \a\t g:i A')) ?>. Reference <?= rfEsc($ref) ?>.
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * The letters the registrar sends on a student's behalf — Request for Form 137-A
 * (Form 007) and Request for NSTP Serial Number. They share one skeleton:
 * letterhead, date, addressee, salutation, a pre-printed body, a one-row table,
 * a closing and a signatory. What differs is described in the form's config:
 * the table's columns, and whether the sheet carries the tick boxes and the
 * Ctrl No. box that only Form 137 has.
 */
function renderLetterDocument(array $request, array $form) {
    $data = decodeRequestForm($request['form_data'] ?? '');
    $head = $form['letterhead'] ?? [];

    $status = $request['status'] ?? 'pending';
    $meta   = requestStatusMeta($status);
    $ref    = '#' . str_pad((string)($request['id'] ?? 0), 5, '0', STR_PAD_LEFT);

    // The letter is dated when the registrar signs it; until then, when it was filed.
    $letter_date = !empty($request['reviewed_at']) ? $request['reviewed_at'] : ($request['submitted_at'] ?? '');
    $letter_date = $letter_date ? date('F j, Y', strtotime($letter_date)) : '';

    // The body keeps its printed emphasis; the markers survive escaping.
    $body = rfEsc($form['body'] ?? '');
    $body = str_replace(
        ['{b}', '{/b}', '{i}', '{/i}', '{u}', '{/u}'],
        ['<strong>', '</strong>', '<em>', '</em>', '<u>', '</u>'],
        $body
    );

    // The NSTP table prints the college's name and address in one cell.
    $school_full = trim(trim((string)($data['school_name'] ?? '')) . ', ' . trim((string)($data['school_address'] ?? '')), ' ,');
    $values = $data + ['school_full' => $school_full];

    $ticks = [
        'first_request'     => !empty($data['first_request']),
        'second_request'    => !empty($data['second_request']),
        'urgent'            => !empty($data['urgent']),
        'entrust_to_bearer' => !empty($data['entrust_to_bearer']),
    ];
    $sig = $form['signatory'] ?? ['name' => '', 'title' => ''];
    $address = trim((string)($data['school_address'] ?? ''));
    $columns = $form['table'] ?? [];

    ob_start();
    ?>
    <article class="rf-doc" data-request="<?= rfEsc($request['id'] ?? '') ?>">
        <section class="rf-page rf-letter">
            <?= rfLetterhead($head, $form['sheet_title'] ?? strtoupper($form['title']), !empty($form['underline_title']), !empty($form['title_below'])) ?>

            <div class="rf-watermark-layer"><div class="rf-watermark rf-watermark-<?= rfEsc($status) ?>"><?= rfEsc(strtoupper($meta['label'])) ?></div></div>

            <div class="rf-letter-date"><?= rfField('Date:', $letter_date, 'rf-inline') ?></div>

            <div class="rf-addressee">
                <div class="rf-addressee-title">THE REGISTRAR</div>
                <span class="rf-fill<?= trim((string)($data['school_name'] ?? '')) === '' ? ' is-blank' : '' ?>"><?= rfEsc($data['school_name'] ?? '') ?></span>
                <span class="rf-fill rf-fill-wrap<?= $address === '' ? ' is-blank' : '' ?>"><?= rfEsc($address) ?></span>
                <span class="rf-fill is-blank"></span>
            </div>

            <p class="rf-salutation"><?= rfEsc($form['salutation'] ?? 'Dear Sir/Madam:') ?></p>
            <p class="rf-letter-body"><?= $body ?></p>

            <table class="rf-table rf-letter-table">
                <thead>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <th style="width: <?= (float)$col['width'] ?>%"><?= rfEsc($col['label']) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($columns as $col): ?>
                            <td><span class="rf-pen-text"><?= rfEsc($values[$col['field']] ?? '') ?></span></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>

            <p class="rf-letter-thanks"><?= rfEsc($form['closing'] ?? '') ?></p>

            <div class="rf-letter-sign">
                <div class="rf-vty">Very truly yours,</div>
                <div class="rf-sig-block">
                    <div class="rf-sig-name"><?= rfEsc($sig['name'] ?? '') ?></div>
                    <div class="rf-sig-role"><?= rfEsc($sig['title'] ?? '') ?></div>
                </div>
            </div>

            <?php if (!empty($form['options'])): ?>
            <div class="rf-ticklist">
                <?php foreach (getForm007Options() as $opt): ?>
                    <div class="rf-tickrow">
                        <span class="rf-tickbox"><?= !empty($ticks[$opt['key']]) ? 'X' : '' ?></span>
                        <span class="rf-ticklabel"><?= rfEsc($opt['label']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($form['ctrl_no'])): ?>
            <div class="rf-ctrlno"><span class="rf-label">Ctrl No. :</span><span class="rf-fill is-blank"></span></div>
            <?php endif; ?>

            <?= rfRegistrarStub($request, $meta, $ref) ?>
        </section>
    </article>
    <?php
    return ob_get_clean();
}
