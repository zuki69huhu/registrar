<?php
/**
 * Student document requests — shared definitions.
 *
 * A "request" is a registrar form a student files online (the paper form the
 * Office of the Registrar used to hand out). The flow is:
 *
 *   student files it  ->  pending
 *   registrar approves ->  approved   + a claim schedule (date, time, where)
 *   student picks it up ->  claimed    + the exact date/time it was released
 *   registrar refuses  ->  rejected   + a written note
 *   student changes mind -> cancelled  (only while still pending)
 *
 * Nothing is decided automatically: a request leaves `pending` only through
 * process_request_status.php, the same way documents only move through
 * process_document_status.php.
 *
 * Which forms a student may file depends on users.institution; see
 * getRequestFormsForInstitution(). MCNP's forms are added there as their paper
 * versions are finalised.
 *
 * The form itself is described once, here, as sections of fields. The student
 * page renders it, the handler validates it and the registrar page prints it
 * back, so the three can never drift apart. The filled answers are stored as
 * JSON in student_requests.form_data, which keeps a new form from needing a
 * schema change.
 */

require_once __DIR__ . '/db.php';

date_default_timezone_set('Asia/Manila');

/** A registrar's note when rejecting a request must be at least this long. */
define('REQUEST_MIN_NOTE_LENGTH', 5);

/** Where a document is picked up unless the registrar types somewhere else. */
define('REQUEST_DEFAULT_CLAIM_LOCATION', "Office of the Registrar");

// ─────────────────────────────────────────────────────────────────────────────
// Table (idempotent, like audit_log_helper.php — this project has no migrations)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $mysqli->query("
        CREATE TABLE IF NOT EXISTS student_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            institution VARCHAR(50) NULL,
            form_type VARCHAR(50) NOT NULL DEFAULT 'graduation',
            form_title VARCHAR(255) NULL,
            form_data LONGTEXT NULL,
            status ENUM('pending','approved','rejected','claimed','cancelled') NOT NULL DEFAULT 'pending',
            student_note TEXT NULL,
            submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by INT NULL,
            reviewed_at DATETIME NULL,
            registrar_note TEXT NULL,
            claim_at DATETIME NULL,
            claim_location VARCHAR(255) NULL,
            claimed_at DATETIME NULL,
            released_by INT NULL,
            claimed_by_name VARCHAR(191) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_req_user (user_id),
            INDEX idx_req_status (status),
            INDEX idx_req_institution (institution),
            CONSTRAINT fk_req_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {
    error_log('student_requests table setup failed: ' . $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────────
// Which forms an institution offers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Forms the given institution can file online, keyed by form_type.
 * An institution with no entry here simply has nothing to request yet.
 */
function getRequestFormsForInstitution($institution) {
    $institution = strtoupper(trim((string)$institution));

    // Every ISAP form carries the same school block; only the document number
    // and the form's own title differ.
    $isap_letterhead = [
        'name'      => 'INTERNATIONAL SCHOOL OF ASIA AND THE PACIFIC',
        'address'   => 'Alimannao Hills, Peñablanca, Cagayan',
        'contact'   => 'Telefax No.: (078) 304-1010/846-7539',
        'website'   => 'www.isap.edu.ph',
        'email'     => 'adminoffice@isap.edu.ph',
        'office'    => 'OFFICE OF THE REGISTRAR',
        'effective' => 'October 1, 2019',
        'revision'  => '00',
        // ISAP prints its name in red; MCNP in blue.
        'color'     => '#c00000',
        // Each institution's own seal, trimmed square from the supplied artwork.
        'logo'      => 'logo_isap.png',
    ];

    // Same building, same telefax — only the name, the web address, the colour
    // and the document number differ.
    $mcnp_letterhead = [
        'name'      => 'MEDICAL COLLEGES OF NORTHERN PHILIPPINES',
        'address'   => 'Alimannao Hills, Peñablanca, Cagayan',
        'contact'   => 'Telefax No.: (078) 304-1010/846-7539',
        'website'   => 'www.mcnp.edu.ph',
        'email'     => 'adminoffice@mcnp.edu.ph',
        'office'    => 'OFFICE OF THE REGISTRAR',
        'effective' => 'October 1, 2019',
        'revision'  => '00',
        // Word's standard Blue, the way ISAP's #C00000 is Word's standard Dark Red.
        'color'     => '#0070c0',
        'logo'      => 'logo_mcnp.png',
    ];

    // The Application for Graduation is the same sheet at both institutions, so
    // it is described once; each one supplies its own code and letterhead.
    $graduation_form = [
        'title'       => 'Application for Graduation',
        'short'       => 'Graduation',
        'icon'        => 'fa-graduation-cap',
        'description' => 'File your application for graduation. Your details are filled in for you — check them, complete the rest, then submit.',

        // What the student ticks before the form can be submitted.
        'confirmation' => getGraduationWaiverText(),

        // The pre-printed signature blocks at the bottom of page 2.
        'recommending' => ['Program Coordinator / College Dean', 'Cashier'],
        'approvers'    => [
            ['name' => 'NESSIE M. JERUSALEM, MAEd', 'title' => 'School Registrar'],
            ['name' => 'CHRISTIAN R. GUZMAN, Ph.D.', 'title' => 'President'],
        ],
    ];

    // Everything printed on Form No. 007, which both institutions use word for
    // word. Two request types are filed on this one sheet, so they share this
    // definition and override only their own title, short name, icon and
    // description; each institution adds its own code and letterhead.
    $form007 = [
        // Both request types print this one heading, because it is the heading
        // of the paper form; only the portal labels them apart.
        'sheet_title'  => 'REQUEST FOR FORM 137-A / TRANSCRIPT OF RECORDS',
        'confirmation' => 'I confirm that the school and the school year above are correct, and I understand that the registrar will send this request to that school on my behalf.',

        // The printed title is underlined on this form.
        'underline_title' => true,

        // The body of the letter is pre-printed; only the blanks are filled.
        'salutation' => 'Dear Sir/Madam:',
        'body'       => 'Please furnish us with the copy of {u}{b}Form 137-A{/b}{/u} / {b}Official Transcript of Records{/b} of the following '
                      . 'student/s, who is/are temporarily enrolled in these institutions upon presentation of their Form '
                      . '138 / transfer credentials.',
        'closing'    => 'Thank you in anticipation for the preferential action you give to this matter.',
        // The one row of the printed table, and the widths it is printed at.
        'table' => [
            ['label' => 'NAME',          'field' => 'student_name',  'width' => 42],
            ['label' => 'STRAND/COURSE', 'field' => 'strand_course', 'width' => 35],
            ['label' => 'SCHOOL YEAR',   'field' => 'school_year',   'width' => 23],
        ],
        // Only this sheet carries the four tick boxes and the Ctrl No. box.
        'options' => true,
        'ctrl_no' => true,
        // The template also carries an Assistant Registrar variant
        // (LANIE L. MACARUBBO, MAEd); swap this entry to use it.
        'signatory'  => ['name' => 'NESSIE M. JERUSALEM, MAEd', 'title' => 'Registrar'],
    ];

    $isap_form007 = $form007 + [
        'code'       => 'ISAP-QMS-DCO-REG Form No. 007',
        'letterhead' => $isap_letterhead + ['doc_no' => 'ISAP-QMS-DCO-REG Form No: 007'],
    ];

    // MCNP's copy is numbered 007RF137, adds the registrar's own address under
    // the e-mail line, prints the title on its own line under the document box
    // and sets its table columns a little differently. The registrar keeps it
    // under two names: the F.137 copy underlines only "Form 137-A" in the body,
    // as ISAP's does; the OTR copy underlines the whole document name.
    $mcnp_form007 = [
        'title_below' => true,
        'table' => [
            ['label' => 'NAME',          'field' => 'student_name',  'width' => 41],
            ['label' => 'STRAND/COURSE', 'field' => 'strand_course', 'width' => 38],
            ['label' => 'SCHOOL YEAR',   'field' => 'school_year',   'width' => 21],
        ],
    ] + $form007 + [
        'code'       => 'MCNP-QMS-DCO-REG Form No. 007RF137',
        'letterhead' => $mcnp_letterhead + [
            'doc_no' => 'MCNP-QMS-DCO-REG Form No: 007RF137',
            'email2' => 'registrar@mcnp.edu.ph',
        ],
    ];

    // The NSTP Serial Number letter, word for word the same at both
    // institutions; each adds its own code, letterhead and table widths.
    $nstp_form = [
        'title'       => 'Request for NSTP Serial Number',
        'short'       => 'NSTP Serial No.',
        'icon'        => 'fa-hashtag',
        'description' => 'Ask the registrar to write to the college where you took NSTP for your assigned NSTP Serial Number.',
        'confirmation' => 'I confirm that the college and the terms above are correct, and I understand that the registrar will send this request to that college on my behalf.',

        'underline_title' => true,

        'salutation' => 'Dear Sir/Madam:',
        'body'       => 'Pursuant to CHED Memorandum Order No. 27, s. 2015 re Guidelines and Procedures on '
                      . 'the Issuance of National Service Training Program (NSTP) Serial Numbers specifically under '
                      . 'Article IV Section 4 which states among others to {i}“Indicate NSTP Serial Number in the Transcript '
                      . 'of Records”{/i}, may we request for the assigned NSTP Serial Number of the following student/s who '
                      . 'took his/her NSTP in your college during the following terms:',
        'closing'    => 'Thank you very much for your immediate response to our request.',
        // The template also carries an Assistant Registrar variant
        // (LANIE L. MACARUBBO, MAEd); swap this entry to use it.
        'signatory'  => ['name' => 'NESSIE M. JERUSALEM, MAEd', 'title' => 'Registrar'],
    ];

    // How the portal labels the two requests filed on Form 007.
    $form137_request = [
        'title'       => 'Request for Form 137-A',
        'short'       => 'Form 137-A',
        'icon'        => 'fa-file-lines',
        'description' => 'Ask the registrar to write to the school you last attended for your Form 137-A — your permanent record from that school.',
    ];
    $otr_request = [
        'title'       => 'Request for Transcript of Records (OTR)',
        'short'       => 'OTR',
        'icon'        => 'fa-scroll',
        'description' => 'Ask the registrar to write to the college you last attended for your Official Transcript of Records.',
    ];

    $forms = [
        'ISAP' => [
            'graduation' => $graduation_form + [
                'code'       => 'ISAP-QMS-DCO-REG Form No. 005',
                'letterhead' => $isap_letterhead + ['doc_no' => 'ISAP-QMS-DCO-REG Form No: 005'],
            ],

            // Form 007 is filed for two different things, and the registrar's
            // template file exists under both names (F.137 and OTR): the Form
            // 137-A is the permanent record from the school last attended, the
            // OTR is the college transcript. The printed sheet is identical, so
            // the two request types share every printed part of it and differ
            // only in what the student is asking for.
            'form137' => $isap_form007 + $form137_request,
            'otr'     => $isap_form007 + $otr_request,

            'nstp' => $nstp_form + [
                // This template carries no QMS form number, so its reference is
                // the CHED order it cites; the letterhead below has no doc_no,
                // which is what leaves the document-control box off the sheet.
                'code'       => 'CHED CMO No. 27, s. 2015',
                'letterhead' => $isap_letterhead,
                'table' => [
                    ['label' => 'NAME',             'field' => 'student_name', 'width' => 27],
                    ['label' => 'SCHOOL & ADDRESS', 'field' => 'school_full',  'width' => 27],
                    ['label' => 'COURSE',           'field' => 'course',       'width' => 20],
                    ['label' => 'NSTP 1 YR. TAKEN', 'field' => 'nstp1_year',   'width' => 13],
                    ['label' => 'NSTP 2 YR. TAKEN', 'field' => 'nstp2_year',   'width' => 13],
                ],
            ],
        ],
        'MCNP' => [
            // The same Application for Graduation, on MCNP's own letterhead.
            // MCNP's other forms go here as their paper versions are finalised.
            'graduation' => $graduation_form + [
                'code'       => 'MCNP-QMS-DCO-REG Form No. 005',
                'letterhead' => $mcnp_letterhead + ['doc_no' => 'MCNP-QMS-DCO-REG Form No: 005'],
            ],

            // MCNP-QMS-DCO-REG Form No. 007RF137, filed as either request, the
            // same way ISAP's Form 007 is; each follows its own template copy.
            'form137' => $mcnp_form007 + $form137_request,
            'otr'     => [
                'body' => 'Please furnish us with the copy of {u}{b}Form 137-A / Official Transcript of Records{/b}{/u} of the following '
                        . 'student/s, who is/are temporarily enrolled in these institutions upon presentation of their Form '
                        . '138 / transfer credentials.',
            ] + $mcnp_form007 + $otr_request,

            // MCNP's NSTP letter is printed on its Form 007RF137 sheet: the same
            // letterhead, document box and title line (which really does read
            // "REQUEST FOR FORM 137-A / TRANSCRIPT OF RECORDS" on the paper), but
            // no tick boxes and no Ctrl No.
            'nstp' => $nstp_form + [
                'code'        => 'MCNP-QMS-DCO-REG Form No. 007RF137',
                'letterhead'  => $mcnp_form007['letterhead'],
                'sheet_title' => $mcnp_form007['sheet_title'],
                'title_below' => true,
                'table' => [
                    ['label' => 'NAME',             'field' => 'student_name', 'width' => 33],
                    ['label' => 'SCHOOL & ADDRESS', 'field' => 'school_full',  'width' => 24],
                    ['label' => 'COURSE',           'field' => 'course',       'width' => 19],
                    ['label' => 'NSTP 1 YR. TAKEN', 'field' => 'nstp1_year',   'width' => 12],
                    ['label' => 'NSTP 2 YR. TAKEN', 'field' => 'nstp2_year',   'width' => 12],
                ],
            ],
        ],
    ];

    return $forms[$institution] ?? [];
}

/** One form's metadata, or null when that institution cannot file it. */
function getRequestForm($institution, $form_type) {
    $forms = getRequestFormsForInstitution($institution);
    return $forms[$form_type] ?? null;
}

/**
 * The fields of a form, whichever form it is. Every consumer goes through here
 * rather than naming a form's own function, so adding a form means adding one
 * case and nothing else.
 */
function getRequestFormSections($form_type) {
    switch ($form_type) {
        case 'graduation': return getGraduationFormSections();
        case 'form137':
        case 'otr':        return getForm007Sections();
        case 'nstp':       return getNstpSections();
        default:           return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// The Application for Graduation form
// ─────────────────────────────────────────────────────────────────────────────

/**
 * The form, section by section. Each field is:
 *   name      POST key and form_data key
 *   label     what the student reads
 *   type      text | date | tel | email | textarea | select | year
 *   required  refuses to submit when blank
 *   autofill  column of `users` this is prefilled from
 *   span      how many grid columns it takes (1-3)
 *   readonly  shown but not editable (still submitted for the record)
 *   options   for type=select
 */
function getGraduationFormSections() {
    return [
        [
            'key'   => 'personal',
            'title' => 'Personal Information',
            'icon'  => 'fa-id-card',
            'note'  => 'Print your complete name exactly as it should appear in your graduation records.',
            'fields' => [
                ['name' => 'course',            'label' => 'Course / Program',   'type' => 'text',     'required' => true,  'autofill' => 'course',         'span' => 2],
                ['name' => 'student_number',    'label' => 'Student Number',     'type' => 'text',     'required' => false, 'autofill' => 'student_id',     'span' => 1, 'readonly' => true],
                ['name' => 'last_name',         'label' => 'Last Name',          'type' => 'text',     'required' => true,  'autofill' => 'last_name',      'span' => 1],
                ['name' => 'first_name',        'label' => 'First Name',         'type' => 'text',     'required' => true,  'autofill' => 'first_name',     'span' => 1],
                ['name' => 'middle_name',       'label' => 'Middle Name',        'type' => 'text',     'required' => false, 'autofill' => 'middle_name',    'span' => 1],
                ['name' => 'date_of_birth',     'label' => 'Date of Birth',      'type' => 'date',     'required' => true,  'span' => 1],
                ['name' => 'sex',               'label' => 'Sex',                'type' => 'select',   'required' => true,  'span' => 1, 'options' => ['Male', 'Female']],
                ['name' => 'civil_status',      'label' => 'Civil Status',       'type' => 'select',   'required' => true,  'span' => 1, 'options' => ['Single', 'Married', 'Widowed', 'Separated']],
                ['name' => 'permanent_address', 'label' => 'Permanent Address',  'type' => 'textarea', 'required' => true,  'autofill' => 'address',        'span' => 3],
                ['name' => 'contact_number',    'label' => 'Contact Number',     'type' => 'tel',      'required' => true,  'autofill' => 'contact_number', 'span' => 1],
                ['name' => 'email',             'label' => 'Email Address',      'type' => 'email',    'required' => true,  'autofill' => 'email',          'span' => 2],
            ],
        ],
        [
            'key'   => 'education',
            'title' => 'Educational Background',
            'icon'  => 'fa-school',
            'note'  => 'College is only for transferees and graduates of any course. Leave it blank if it does not apply to you.',
            'fields' => [
                ['name' => 'elementary',       'label' => 'Elementary',       'type' => 'text', 'required' => true,  'span' => 2],
                ['name' => 'elementary_year',  'label' => 'Year Graduated',   'type' => 'year', 'required' => true,  'span' => 1],
                ['name' => 'junior_high',      'label' => 'Junior High School', 'type' => 'text', 'required' => true, 'span' => 2],
                ['name' => 'junior_high_year', 'label' => 'Year Graduated',   'type' => 'year', 'required' => true,  'span' => 1],
                ['name' => 'senior_high',      'label' => 'Senior High School', 'type' => 'text', 'required' => true, 'span' => 2],
                ['name' => 'senior_high_year', 'label' => 'Year Graduated',   'type' => 'year', 'required' => true,  'span' => 1],
                ['name' => 'college',          'label' => 'College (transferees & graduates of any course)', 'type' => 'text', 'required' => false, 'span' => 2],
                ['name' => 'college_year',     'label' => 'Year Ended',       'type' => 'year', 'required' => false, 'span' => 1],
            ],
        ],
    ];
}

/**
 * The documents listed on the form. `transferee` items only apply to students
 * who tick "I am a transferee", so they are never required of everyone.
 */
function getGraduationRequirements() {
    return [
        ['key' => 'form137',          'label' => 'Form 137 / TOR from last school attended',             'group' => 'all'],
        ['key' => 'psa_birth',        'label' => 'PSA Birth Certificate (photocopy)',                    'group' => 'all'],
        ['key' => 'photo_2x2',        'label' => 'One 2x2 picture (white background, with name tag)',    'group' => 'all'],
        ['key' => 'psa_correction',   'label' => 'PSA approved correction of name / birthdate',          'group' => 'all', 'optional' => true],
        ['key' => 'good_moral',       'label' => 'Certificate of Good Moral Character from school last attended', 'group' => 'transferee'],
        ['key' => 'honorable',        'label' => 'Honorable Dismissal / Transfer Credential',            'group' => 'transferee'],
        ['key' => 'police_clearance', 'label' => 'Police Clearance / NBI Clearance (latest)',            'group' => 'transferee'],
        ['key' => 'completion_forms', 'label' => 'Completion Forms',                                     'group' => 'all'],
        ['key' => 'nstp',             'label' => 'NSTP Grades / Diploma / Certificate of Completion',    'group' => 'all'],
    ];
}

/**
 * Items 8 and 9 of the paper form. The student affirms each one; all are
 * required, because the registrar cannot process the application otherwise.
 */
function getGraduationDeclarations() {
    return [
        ['key' => 'no_failing_grades', 'label' => 'I have no failing and/or incomplete grade/s in any academic or non-academic subject.'],
        ['key' => 'department_clearance', 'label' => 'I have obtained clearance from all departments of the institution concerned.'],
        ['key' => 'financial_obligations', 'label' => 'I have paid all my financial obligations to the school.'],
        ['key' => 'practicum', 'label' => 'I have submitted all practicum requirements specified by the practicum coordinator.'],
        ['key' => 'organization_clearance', 'label' => 'I have been cleared from obligations in department-affiliated organization/s.'],
    ];
}

/** The waiver printed at the bottom of the paper form. */
function getGraduationWaiverText() {
    return 'I am aware that non-compliance/completion of all academic, non-academic and document requirements '
         . 'can cause my failure to graduate, and I shall not hold the college/school responsible for all my deficiency/ies.';
}

// ─────────────────────────────────────────────────────────────────────────────
// Form 007 — filed either as a Form 137-A request or as an OTR request
// ─────────────────────────────────────────────────────────────────────────────

/**
 * What a Form 007 request asks for — the same fields whichever of the two it is
 * filed as. Everything else on the sheet is pre-printed letter text, so there is
 * very little to fill in: who to write to, which student, and how urgent it is.
 *
 * `checkbox` fields are never required; they are stored as true/false.
 */
function getForm007Sections() {
    return [
        [
            'key'   => 'school',
            'title' => 'School You Last Attended',
            'icon'  => 'fa-school',
            'note'  => 'The registrar addresses the letter to this school, so write the name and address exactly as they should appear.',
            'fields' => [
                ['name' => 'school_name',    'label' => 'Name of School', 'type' => 'text',     'required' => true, 'span' => 3],
                ['name' => 'school_address', 'label' => 'School Address', 'type' => 'textarea', 'required' => true, 'span' => 3],
            ],
        ],
        [
            'key'   => 'student',
            'title' => 'Student Details',
            'icon'  => 'fa-id-card',
            'note'  => 'This is the NAME / STRAND-COURSE / SCHOOL YEAR row printed in the letter.',
            'fields' => [
                ['name' => 'student_name',  'label' => 'Name',           'type' => 'text', 'required' => true, 'autofill' => 'full_name', 'span' => 2],
                ['name' => 'strand_course', 'label' => 'Strand / Course', 'type' => 'text', 'required' => true, 'autofill' => 'course',    'span' => 1],
                ['name' => 'school_year',   'label' => 'School Year at that school', 'type' => 'text', 'required' => true, 'span' => 1, 'placeholder' => 'e.g. 2022-2023'],
            ],
        ],
        [
            'key'   => 'details',
            'title' => 'Request Details',
            'icon'  => 'fa-clipboard-check',
            'note'  => 'These are the boxes ticked at the bottom of the letter.',
            'fields' => [
                ['name' => 'request_type',      'label' => 'Which request is this?', 'type' => 'select', 'required' => true, 'span' => 1, 'options' => ['First Request', 'Second Request']],
                ['name' => 'urgent',            'label' => 'Urgent',                 'type' => 'checkbox', 'span' => 1],
                ['name' => 'entrust_to_bearer', 'label' => 'Please entrust to bearer', 'type' => 'checkbox', 'span' => 1],
            ],
        ],
    ];
}

/**
 * Request for NSTP Serial Number — what the student supplies. The letter body
 * and the CHED citation are pre-printed; only the college, the student row and
 * the two terms are filled in.
 */
function getNstpSections() {
    return [
        [
            'key'   => 'college',
            'title' => 'College Where You Took NSTP',
            'icon'  => 'fa-school-flag',
            'note'  => 'The registrar addresses the letter to this college, and its name and address are printed in the table too.',
            'fields' => [
                ['name' => 'school_name',    'label' => 'Name of College', 'type' => 'text',     'required' => true, 'span' => 3],
                ['name' => 'school_address', 'label' => 'College Address', 'type' => 'textarea', 'required' => true, 'span' => 3],
            ],
        ],
        [
            'key'   => 'student',
            'title' => 'Student Details',
            'icon'  => 'fa-id-card',
            'note'  => 'This is the row printed in the letter: your name, the college, your course, and the terms you took NSTP.',
            'fields' => [
                ['name' => 'student_name', 'label' => 'Name',              'type' => 'text', 'required' => true, 'autofill' => 'full_name', 'span' => 2],
                ['name' => 'course',       'label' => 'Course',            'type' => 'text', 'required' => true, 'autofill' => 'course',    'span' => 1],
                ['name' => 'nstp1_year',   'label' => 'NSTP 1 — year taken', 'type' => 'text', 'required' => true,  'span' => 1, 'placeholder' => 'e.g. 2021-2022'],
                ['name' => 'nstp2_year',   'label' => 'NSTP 2 — year taken', 'type' => 'text', 'required' => false, 'span' => 1, 'placeholder' => 'Leave blank if not yet taken'],
            ],
        ],
    ];
}

/** The four tick boxes printed at the foot of Form 007, in their printed order. */
function getForm007Options() {
    return [
        ['key' => 'first_request',     'label' => 'First Request'],
        ['key' => 'second_request',    'label' => 'Second Request'],
        ['key' => 'urgent',            'label' => 'Urgent'],
        ['key' => 'entrust_to_bearer', 'label' => 'Please entrust to bearer'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Status presentation
// ─────────────────────────────────────────────────────────────────────────────

/** Label, colour and icon for a status, so every page badges it the same way. */
function requestStatusMeta($status) {
    $map = [
        'pending'   => ['label' => 'Pending',        'student' => 'Waiting for registrar review', 'color' => '#f59e0b', 'icon' => 'fa-hourglass-half'],
        'approved'  => ['label' => 'Ready for claim', 'student' => 'Approved — ready for claim',  'color' => '#10b981', 'icon' => 'fa-circle-check'],
        'claimed'   => ['label' => 'Claimed',        'student' => 'Claimed',                      'color' => '#3b82f6', 'icon' => 'fa-hand-holding-heart'],
        'rejected'  => ['label' => 'Not approved',   'student' => 'Not approved',                 'color' => '#ef4444', 'icon' => 'fa-circle-xmark'],
        'cancelled' => ['label' => 'Cancelled',      'student' => 'Cancelled by you',             'color' => '#64748b', 'icon' => 'fa-ban'],
    ];
    return $map[$status] ?? ['label' => ucfirst((string)$status), 'student' => ucfirst((string)$status), 'color' => '#64748b', 'icon' => 'fa-circle'];
}

/**
 * The short name a filter chip shows for a form. Falls back to the title stored
 * on the request, so a form that was later removed still reads sensibly.
 */
function requestFormLabel($institution, $form_type, $fallback = '') {
    $form = getRequestForm($institution, $form_type);
    if ($form) return $form['short'] ?? $form['title'];
    return $fallback !== '' ? $fallback : ucfirst((string)$form_type);
}

/** A request still in play — the student may not file the same form twice. */
function isRequestOpen($status) {
    return in_array($status, ['pending', 'approved'], true);
}

/** "Mon, 12 Jan 2026 · 9:00 AM", or an em dash when nothing is scheduled. */
function formatClaimSchedule($claim_at) {
    if (empty($claim_at) || $claim_at === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($claim_at);
    if (!$ts) return '—';
    return date('D, M j, Y', $ts) . ' · ' . date('g:i A', $ts);
}

/** Decodes form_data, always returning an array. */
function decodeRequestForm($json) {
    if (empty($json)) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

// ─────────────────────────────────────────────────────────────────────────────
// Counts for the sidebar badge
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pending requests the signed-in registrar is responsible for, scoped to their
 * own institution (unscoped when the registrar has no institution set, the same
 * fallback the other registrar queries use). Returns 0 on any failure so a
 * sidebar can call it without guarding.
 */
function pendingRequestCount($mysqli) {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'registrar') return 0;

    try {
        $institution = '';
        $stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $institution = $stmt->get_result()->fetch_assoc()['institution'] ?? '';
            $stmt->close();
        }

        if (!empty($institution)) {
            $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM student_requests r JOIN users u ON r.user_id = u.id
                                      WHERE r.status = 'pending' AND u.institution = ?");
            if (!$stmt) return 0;
            $stmt->bind_param("s", $institution);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            return $count;
        }

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM student_requests WHERE status = 'pending'");
        return $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * The sidebar entry for the registrar queue. Every registrar page repeats its
 * own sidebar markup (this project has no includes), so the link — badge and
 * all — is built here once instead of being copied ten times.
 */
function requestsNavLink($mysqli, $active = false) {
    $count = pendingRequestCount($mysqli);
    $badge = $count > 0 ? ' <span class="nav-badge">' . $count . '</span>' : '';
    return '<a href="registrar_requests.php" class="nav-link' . ($active ? ' active' : '') . '">'
         . '<i class="fas fa-file-signature"></i> Document Requests' . $badge . '</a>';
}

/**
 * Uploaded documents still waiting for the signed-in registrar's decision,
 * scoped to their institution exactly like submissions.php lists them.
 * Returns 0 on any failure so a sidebar can call it without guarding.
 */
function pendingDocumentCount($mysqli) {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'registrar') return 0;

    try {
        $institution = '';
        $stmt = $mysqli->prepare("SELECT institution FROM users WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $institution = $stmt->get_result()->fetch_assoc()['institution'] ?? '';
            $stmt->close();
        }

        if (!empty($institution)) {
            $stmt = $mysqli->prepare("SELECT COUNT(*) AS c FROM documents d JOIN users u ON d.user_id = u.id
                                      WHERE d.status = 'pending' AND u.institution = ?");
            if (!$stmt) return 0;
            $stmt->bind_param("s", $institution);
            $stmt->execute();
            $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
            $stmt->close();
            return $count;
        }

        $res = $mysqli->query("SELECT COUNT(*) AS c FROM documents d JOIN users u ON d.user_id = u.id WHERE d.status = 'pending'");
        return $res ? (int)($res->fetch_assoc()['c'] ?? 0) : 0;
    } catch (Exception $e) {
        return 0;
    }
}

/** The Submissions sidebar entry, with the red count of documents awaiting review. */
function submissionsNavLink($mysqli, $active = false) {
    $count = pendingDocumentCount($mysqli);
    $badge = $count > 0
        ? ' <span class="nav-badge" title="' . $count . ' document' . ($count === 1 ? '' : 's') . ' awaiting review">' . $count . '</span>'
        : '';
    return '<a href="submissions.php" class="nav-link' . ($active ? ' active' : '') . '">'
         . '<i class="fas fa-file-alt"></i> Submissions' . $badge . '</a>';
}
