<?php
/**
 * Auto-Verification Helper
 *
 * Uploaded documents are checked for clarity only: blurry documents are rejected,
 * clear documents are approved automatically.
 *
 * How sharpness is measured (PHP GD, no external tools):
 * - The image is resized to DOC_ANALYSIS_SIZE px on its long side, so the score means
 *   "how blurry does it look at a readable size" regardless of camera resolution.
 * - The image is split into tiles. Tiles without content (blank paper, plain walls) are ignored.
 * - For each content tile, fine detail (mean |Laplacian| minus the image's noise floor) is divided
 *   by the tile's coarse contrast. Dividing by contrast makes dim or faded photos score fairly;
 *   subtracting the noise floor stops grainy low-light photos from looking sharp.
 * - The final score is the 75th percentile across content tiles, so a photo that is slightly out
 *   of focus near the edges still passes when most of the text is sharp.
 *
 * PDFs: embedded JPEG page images (phone-scan / scanner PDFs) are extracted and checked the same
 * way. PDFs without embedded scans are digital documents and are approved.
 */

define('DOC_MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
define('DOC_ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png']);

define('DOC_ANALYSIS_SIZE', 1000);        // long side (px) the image is normalized to
define('DOC_TILE_SIZE', 40);              // tile size (px) at analysis size; must be divisible by 4
define('DOC_MIN_TILE_CONTRAST', 8);       // coarse std-dev a tile needs to count as content
define('DOC_MIN_CONTENT_TILES', 6);       // fewer content tiles than this = nothing readable
define('DOC_SHARPNESS_THRESHOLD', 0.21);  // clarity score below this is rejected as blurry
define('DOC_MIN_IMAGE_SIDE', 300);        // long side (px) below this is too small to read
define('DOC_MAX_IMAGE_PIXELS', 80000000); // larger images are sent to manual review (memory)
define('DOC_MIN_PDF_SCAN_SIDE', 800);     // embedded PDF images at least this big are page scans
define('DOC_MAX_PDF_SCANS', 10);          // page scans analyzed per PDF

function docCloneImage($img) {
    $copy = imagecreatetruecolor(imagesx($img), imagesy($img));
    imagecopy($copy, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
    return $copy;
}

/**
 * Average of each tile, computed natively by resampling the image down to one pixel per tile.
 * Returns a flat array indexed [tileY * tilesX + tileX] with values 0..255.
 */
function docTileAverages($img, $tilesX, $tilesY, $cropW, $cropH) {
    $small = imagecreatetruecolor($tilesX, $tilesY);
    imagecopyresampled($small, $img, 0, 0, 0, 0, $tilesX, $tilesY, $cropW, $cropH);
    $values = [];
    for ($y = 0; $y < $tilesY; $y++) {
        for ($x = 0; $x < $tilesX; $x++) {
            $values[] = imagecolorat($small, $x, $y) & 0xFF;
        }
    }
    return $values;
}

/**
 * Measure how sharp an image is.
 *
 * @param resource|GdImage $img
 * @return array ['score' => float, 'content_tiles' => int]
 */
function docMeasureSharpness($img) {
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, DOC_ANALYSIS_SIZE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    // Normalize size; transparent areas are flattened onto white paper
    $work = imagecreatetruecolor($nw, $nh);
    imagefill($work, 0, 0, imagecolorallocate($work, 255, 255, 255));
    imagecopyresampled($work, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagefilter($work, IMG_FILTER_GRAYSCALE);

    $tile = DOC_TILE_SIZE;
    $tilesX = intdiv($nw, $tile);
    $tilesY = intdiv($nh, $tile);
    if ($tilesX < 2 || $tilesY < 2) {
        return ['score' => 0.0, 'content_tiles' => 0];
    }
    $cropW = $tilesX * $tile;
    $cropH = $tilesY * $tile;

    // Fine detail: |Laplacian| scaled x8. GD clamps results to 0..255, so the positive and
    // negative halves are computed separately and added back together per tile.
    $pos = docCloneImage($work);
    imageconvolution($pos, [[0, 8, 0], [8, -32, 8], [0, 8, 0]], 1, 0);
    $neg = docCloneImage($work);
    imageconvolution($neg, [[0, -8, 0], [-8, 32, -8], [0, -8, 0]], 1, 0);
    $posAvg = docTileAverages($pos, $tilesX, $tilesY, $cropW, $cropH);
    $negAvg = docTileAverages($neg, $tilesX, $tilesY, $cropW, $cropH);

    // Coarse contrast: std-dev inside each tile of a 4x-downscaled copy. Downscaling averages
    // away noise and is barely affected by blur, so this says "is there content here".
    $cell = intdiv($tile, 4);
    $smallW = $tilesX * $cell;
    $smallH = $tilesY * $cell;
    $small = imagecreatetruecolor($smallW, $smallH);
    imagecopyresampled($small, $work, 0, 0, 0, 0, $smallW, $smallH, $cropW, $cropH);

    $fine = [];
    $contrast = [];
    for ($ty = 0; $ty < $tilesY; $ty++) {
        for ($tx = 0; $tx < $tilesX; $tx++) {
            $i = $ty * $tilesX + $tx;
            $fine[$i] = ($posAvg[$i] + $negAvg[$i]) / 8;

            $n = 0; $sum = 0; $sumSq = 0;
            for ($y = $ty * $cell, $yEnd = $y + $cell; $y < $yEnd; $y++) {
                for ($x = $tx * $cell, $xEnd = $x + $cell; $x < $xEnd; $x++) {
                    $v = imagecolorat($small, $x, $y) & 0xFF;
                    $n++;
                    $sum += $v;
                    $sumSq += $v * $v;
                }
            }
            $mean = $sum / $n;
            $contrast[$i] = sqrt(max(0, $sumSq / $n - $mean * $mean));
        }
    }

    // Noise floor: the fine detail found in the flattest tiles is sensor/JPEG noise, not text
    $sortedFine = $fine;
    sort($sortedFine);
    $noise = $sortedFine[(int)floor((count($sortedFine) - 1) * 0.10)];

    $ratios = [];
    foreach ($fine as $i => $f) {
        if ($contrast[$i] < DOC_MIN_TILE_CONTRAST) continue;
        $signal = sqrt(max(0, $f * $f - $noise * $noise));
        $ratios[] = $signal / $contrast[$i];
    }

    if (count($ratios) < DOC_MIN_CONTENT_TILES) {
        return ['score' => 0.0, 'content_tiles' => count($ratios)];
    }
    sort($ratios);
    return [
        'score' => $ratios[(int)floor((count($ratios) - 1) * 0.75)],
        'content_tiles' => count($ratios),
    ];
}

/**
 * Extract embedded JPEG images (DCTDecode streams) from a PDF.
 * Scanner and phone-scan apps store each page as a JPEG, so these are the page scans.
 *
 * @return string[] raw JPEG bytes, in document order
 */
function docExtractPdfJpegs($pdfData) {
    $images = [];
    $offset = 0;
    while (preg_match('/\bstream\r?\n/', $pdfData, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $streamStart = $m[0][1] + strlen($m[0][0]);
        $endPos = strpos($pdfData, 'endstream', $streamStart);
        if ($endPos === false) break;
        $offset = $endPos + 9;

        // The stream's dictionary sits between the preceding "obj" keyword and "stream"
        $dictStart = strrpos(substr($pdfData, max(0, $m[0][1] - 4096), min(4096, $m[0][1])), 'obj');
        $dict = $dictStart === false ? '' : substr($pdfData, max(0, $m[0][1] - 4096) + $dictStart, 4096);
        $dict = substr($dict, 0, strpos($dict, 'stream') ?: strlen($dict));
        if (strpos($dict, '/DCTDecode') === false || !preg_match('/\/Subtype\s*\/Image/', $dict)) continue;
        // Skip images with extra filters layered on the JPEG (e.g. Flate + DCT)
        if (preg_match('/\/Filter\s*\[[^\]]*\/(?!DCTDecode)\w+Decode/', $dict)) continue;

        $jpeg = substr($pdfData, $streamStart, $endPos - $streamStart);
        $jpegEnd = strrpos($jpeg, "\xFF\xD9");
        if (substr($jpeg, 0, 2) !== "\xFF\xD8" || $jpegEnd === false) continue;
        $images[] = substr($jpeg, 0, $jpegEnd + 2);
    }
    return $images;
}

/**
 * Decode image bytes safely. Returns [GdImage|null, error message|null].
 */
function docDecodeImage($bytes) {
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        return [null, 'unreadable'];
    }
    if ($info[0] * $info[1] > DOC_MAX_IMAGE_PIXELS) {
        return [null, 'too_large'];
    }
    $img = @imagecreatefromstring($bytes);
    return $img ? [$img, null] : [null, 'unreadable'];
}

function docClarityLabel($score) {
    return (int)round($score * 100);
}

/**
 * Auto-verify a document: reject when blurry, approve when clear.
 *
 * @param string $filePath     Path to the uploaded file
 * @param string $documentType The document type label (kept for callers; not used for the decision)
 * @return array ['status' => 'approved'|'rejected'|'pending', 'feedback' => string, 'auto' => bool, 'blur_score' => float|null]
 */
function autoVerifyDocument($filePath, $documentType = '') {
    $minLabel = docClarityLabel(DOC_SHARPNESS_THRESHOLD);
    $retakeTips = 'Please retake the photo or scan in good lighting, hold the camera steady, and make sure all text is in focus.';
    $result = function ($status, $feedback, $score = null) {
        return ['status' => $status, 'feedback' => $feedback, 'auto' => true, 'blur_score' => $score];
    };

    if (!function_exists('imagecreatefromstring') || !function_exists('imageconvolution')) {
        return $result('pending', 'Uploaded successfully. Automatic clarity check is unavailable, so the registrar will review this document.');
    }

    @set_time_limit(120);
    $bytes = @file_get_contents($filePath);
    if ($bytes === false || $bytes === '') {
        return $result('rejected', 'Auto-Rejected: The uploaded file is empty or could not be read. Please upload the file again.');
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'pdf') {
        if (strpos(substr($bytes, 0, 1024), '%PDF-') === false) {
            return $result('rejected', 'Auto-Rejected: The file is not a valid PDF document. Please upload the original PDF, or a clear photo (JPG/PNG) of the document.');
        }

        $scores = [];
        foreach (docExtractPdfJpegs($bytes) as $jpeg) {
            [$img, $error] = docDecodeImage($jpeg);
            if (!$img || max(imagesx($img), imagesy($img)) < DOC_MIN_PDF_SCAN_SIDE) continue;
            $scores[] = docMeasureSharpness($img)['score'];
            if (count($scores) >= DOC_MAX_PDF_SCANS) break;
        }

        if (!$scores) {
            return $result('approved', 'Auto-Approved: Digital PDF document accepted.');
        }

        $worst = min($scores);
        $worstPage = array_search($worst, $scores) + 1;
        if ($worst < DOC_SHARPNESS_THRESHOLD) {
            $where = count($scores) > 1 ? " (scanned page {$worstPage} of " . count($scores) . ')' : '';
            return $result('rejected', "Auto-Rejected: The scanned document is too blurry to read{$where}. Clarity score " . docClarityLabel($worst) . ", minimum {$minLabel}. {$retakeTips}", $worst);
        }
        return $result('approved', 'Auto-Approved: The scanned document is clear and readable (clarity score ' . docClarityLabel($worst) . ').', $worst);
    }

    [$img, $error] = docDecodeImage($bytes);
    if ($error === 'too_large') {
        return $result('pending', 'Uploaded successfully. The image resolution is too large for the automatic clarity check, so the registrar will review this document.');
    }
    if (!$img) {
        return $result('rejected', 'Auto-Rejected: The image file is damaged or is not a real JPG/PNG image. Please upload the photo again.');
    }

    if (max(imagesx($img), imagesy($img)) < DOC_MIN_IMAGE_SIDE) {
        return $result('rejected', 'Auto-Rejected: The image is too small (' . imagesx($img) . 'x' . imagesy($img) . ' px) to read clearly. Please upload a higher-resolution photo or scan.', 0.0);
    }

    $analysis = docMeasureSharpness($img);
    $score = $analysis['score'];

    if ($analysis['content_tiles'] < DOC_MIN_CONTENT_TILES) {
        return $result('rejected', "Auto-Rejected: No readable text could be found — the image is too blurry, too dark, or blank. {$retakeTips}", $score);
    }
    if ($score < DOC_SHARPNESS_THRESHOLD) {
        return $result('rejected', 'Auto-Rejected: The document image is too blurry to read. Clarity score ' . docClarityLabel($score) . ", minimum {$minLabel}. {$retakeTips}", $score);
    }
    return $result('approved', 'Auto-Approved: The document is clear and readable (clarity score ' . docClarityLabel($score) . ').', $score);
}
