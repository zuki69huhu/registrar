<?php
/**
 * A small .xlsx writer: one sheet, a bold header row that stays frozen at the
 * top, column widths, and an AutoFilter on the header. Every cell is written
 * as text, so a student number such as 2023-00101 or a contact number that
 * starts with 0 reaches Excel exactly as typed.
 *
 * This XAMPP's PHP has no zip extension, so the .xlsx container (a ZIP) is
 * assembled here with gzdeflate() and crc32(), which every PHP has.
 *
 *   $bytes = xlsxBuild('Student Profiles', $headers, $rows, $widths);
 *   header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
 *   echo $bytes;
 */

/** "A", "B", ... "Z", "AA", ... for a 0-based column index. */
function xlsxColumn($index) {
    $name = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $name = chr(65 + ($n - 1) % 26) . $name;
    }
    return $name;
}

/** Text safe inside the XML: escaped, and without the control characters XML forbids. */
function xlsxText($value) {
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$value) ?? '';
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param string $sheet_name  Tab name (Excel allows 31 characters)
 * @param array  $headers     Column titles
 * @param array  $rows        Rows of values, in the same order as $headers
 * @param array  $widths      Column widths in Excel's character units
 * @return string             The .xlsx file
 */
function xlsxBuild($sheet_name, array $headers, array $rows, array $widths = []) {
    $last_col = xlsxColumn(max(count($headers), 1) - 1);
    $last_row = count($rows) + 1;

    $cols = '';
    foreach ($headers as $i => $unused) {
        $w = (float)($widths[$i] ?? 18);
        $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }

    $sheet_rows = '';
    $all = array_merge([$headers], $rows);
    foreach ($all as $r => $row) {
        $style = $r === 0 ? ' s="1"' : ' s="2"';
        $cells = '';
        foreach (array_values($row) as $c => $value) {
            $ref = xlsxColumn($c) . ($r + 1);
            $cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . xlsxText($value) . '</t></is></c>';
        }
        $sheet_rows .= '<row r="' . ($r + 1) . '"' . ($r === 0 ? ' ht="30" customHeight="1"' : '') . '>' . $cells . '</row>';
    }

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<dimension ref="A1:' . $last_col . $last_row . '"/>'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
        . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/>'
        . '<cols>' . $cols . '</cols>'
        . '<sheetData>' . $sheet_rows . '</sheetData>'
        . '<autoFilter ref="A1:' . $last_col . $last_row . '"/>'
        . '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
        . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        . '</worksheet>';

    $name = xlsxText(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $sheet_name), 0, 31));
    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $name . '\'!$A$1:$' . $last_col . '$' . $last_row . '</definedName></definedNames>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        // 0 default, 1 header (bold white on navy, centred, wrapped), 2 body (top-aligned)
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F3864"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right>'
            . '<top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return xlsxZip($files);
}

/** A ZIP archive of the given files (name => contents), deflated. */
function xlsxZip(array $files) {
    $local = '';
    $central = '';
    $offset = 0;
    $time = 0; $date = (2026 - 1980) << 9 | 1 << 5 | 1;   // a fixed stamp; Excel does not read it
    foreach ($files as $name => $data) {
        $deflated = gzdeflate($data, 6);
        $crc = crc32($data);
        $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($data), strlen($name), 0);
        $local .= $header . $name . $deflated;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 8, $time, $date, $crc, strlen($deflated), strlen($data),
                         strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $offset += strlen($header) + strlen($name) + strlen($deflated);
    }
    return $local . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0);
}
