<?php
/**
 * Student / user name formatting.
 *
 * Every list in the portal shows a person the same way: surname first, so rows
 * sort and scan the way a registrar reads a class list.
 *
 *   formatPersonName('Maricris', 'Reyes', 'Ganal')  =>  "Ganal, Maricris R."
 *
 * Tables that list students put the Student ID column first, then this name.
 */

/**
 * "Last, First M." — falls back gracefully when parts are missing.
 */
function formatPersonName($first, $middle = '', $last = '') {
    $first  = trim((string)$first);
    $middle = trim((string)$middle);
    $last   = trim((string)$last);

    // Middle name becomes a single initial: "Reyes" -> "R."
    $initial = '';
    if ($middle !== '') {
        $letter = mb_substr($middle, 0, 1);
        // Already written as an initial ("R." / "R")
        $initial = mb_strtoupper($letter) . '.';
    }

    if ($last === '' && $first === '') return '';
    if ($last === '') return trim($first . ' ' . $initial);
    if ($first === '') return $last;

    return $last . ', ' . trim($first . ' ' . $initial);
}

/**
 * Same thing straight from a row of `users` (or a JOIN that aliases those columns).
 */
function formatPersonNameRow($row, $prefix = '') {
    return formatPersonName(
        $row[$prefix . 'first_name'] ?? '',
        $row[$prefix . 'middle_name'] ?? '',
        $row[$prefix . 'last_name'] ?? ''
    );
}

/**
 * A student number is always shown; blank ones read as "—" rather than an empty cell.
 */
function formatStudentId($student_id) {
    $student_id = trim((string)$student_id);
    return $student_id !== '' ? $student_id : '—';
}
