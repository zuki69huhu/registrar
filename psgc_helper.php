<?php
/**
 * Philippine addresses: region -> province -> city/municipality -> barangay.
 *
 * The lists are local files in psgc/, built from the Philippine Standard
 * Geographic Code, so the address picker works with no internet connection:
 *
 *   psgc/regions.json            [{"c": region code, "n": name, "p": [{"c": province code, "n": name}]}]
 *   psgc/p_<province code>.json  [{"n": city or municipality, "b": [barangay, ...]}]
 *
 * A "province" here is the first four digits of a PSGC code, which also places
 * the independent cities (Davao City sits under Davao del Sur). Metro Manila
 * has districts instead of provinces; they are listed as "Metro Manila - ...".
 *
 * address_picker.js reads the same files in the browser to fill the four
 * dropdowns; this file is the server's side of it, so a posted address is
 * checked against the very lists it was picked from.
 */

/** The region list with each region's provinces, or [] when the data is missing. */
function psgcRegions() {
    static $regions = null;
    if ($regions === null) {
        $json = @file_get_contents(__DIR__ . '/psgc/regions.json');
        $regions = $json ? (json_decode($json, true) ?: []) : [];
    }
    return $regions;
}

/** The cities and municipalities of one province, each with its barangays. */
function psgcProvinceRows($province_code) {
    static $cache = [];
    if (!preg_match('/^\d{4}$/', (string)$province_code)) return [];
    if (!isset($cache[$province_code])) {
        $json = @file_get_contents(__DIR__ . '/psgc/p_' . $province_code . '.json');
        $cache[$province_code] = $json ? (json_decode($json, true) ?: []) : [];
    }
    return $cache[$province_code];
}

/**
 * Checks a picked address, level by level.
 *
 * The levels must be filled from the top without gaps (no province without a
 * region), each one must lie inside the one above it, and at least $min_depth
 * of them must be given (4 = down to the barangay, 0 = may be left empty).
 * Returns '' when the address is fine, otherwise what is wrong with it.
 *
 * When the data files are missing nothing can be checked, so the address is
 * accepted rather than locking every student out of the form.
 */
function psgcCheckPlace($region, $province, $city, $barangay, $min_depth = 4) {
    $levels = [(string)$region, (string)$province, (string)$city, (string)$barangay];
    $depth = 0;
    foreach ($levels as $i => $value) {
        if ($value === '') break;
        $depth = $i + 1;
    }
    foreach (array_slice($levels, $depth) as $value) {
        if ($value !== '') return 'choose it again from the top, starting with the region';
    }
    if ($depth < $min_depth) {
        return ['choose the region', 'choose the province', 'choose the city / municipality', 'choose the barangay'][$depth];
    }
    if ($depth === 0) return '';

    $regions = psgcRegions();
    if (!$regions) return '';

    foreach ($regions as $r) {
        if (($r['n'] ?? '') !== $levels[0]) continue;
        if ($depth === 1) return '';
        foreach ($r['p'] ?? [] as $p) {
            if (($p['n'] ?? '') !== $levels[1]) continue;
            if ($depth === 2) return '';
            foreach (psgcProvinceRows($p['c'] ?? '') as $row) {
                if (($row['n'] ?? '') !== $levels[2]) continue;
                if ($depth === 3) return '';
                return in_array($levels[3], $row['b'] ?? [], true) ? '' : 'that barangay is not in the chosen city / municipality';
            }
            return 'that city / municipality is not in the chosen province';
        }
        return 'that province is not in the chosen region';
    }
    return 'choose the region from the list';
}
