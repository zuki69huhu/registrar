<?php
/**
 * Keeps a copy of every uploaded file inside the database.
 *
 * Uploads are saved to uploads/ as before, but the files on disk do not travel
 * with the database: when the project is moved to another computer, or the DB
 * is restored from a backup, the documents rows arrive and the pictures do not.
 * Storing the bytes in `upload_files` as well means a DB backup carries every
 * upload, and uploads/_restore.php writes a missing file back on first request.
 *
 * Files are stored in chunks because MySQL's default max_allowed_packet is 1 MB.
 * The tables use Aria rather than InnoDB: on this XAMPP install creating a new
 * InnoDB table crashed mysqld, and Aria keeps these copies out of that tablespace.
 * Every function here fails quietly: the copy is a safety net, never a reason
 * for an upload to fail.
 */

const UPLOAD_STORE_CHUNK = 512 * 1024;

function ensureUploadStoreTables(mysqli $mysqli): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $mysqli->query("CREATE TABLE IF NOT EXISTS upload_files (
            path VARCHAR(191) NOT NULL PRIMARY KEY,
            mime VARCHAR(100) NOT NULL,
            size INT UNSIGNED NOT NULL,
            chunks INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=Aria DEFAULT CHARSET=utf8mb4");
        $mysqli->query("CREATE TABLE IF NOT EXISTS upload_file_chunks (
            path VARCHAR(191) NOT NULL,
            seq INT UNSIGNED NOT NULL,
            data MEDIUMBLOB NOT NULL,
            PRIMARY KEY (path, seq)
        ) ENGINE=Aria DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('upload store: ' . $e->getMessage());
    }
}

/** Relative path as stored in the DB ("uploads/..."), with forward slashes. */
function normaliseUploadPath(string $path): string {
    return ltrim(str_replace('\\', '/', $path), '/');
}

/** A file name that is safe in a URL and on any file system; keeps the extension. */
function safeUploadFileName(string $original): string {
    $ext  = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $base = pathinfo($original, PATHINFO_FILENAME);
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    $base = trim(substr($base, 0, 80), '._-');
    if ($base === '') $base = 'file';
    return $ext !== '' ? "{$base}.{$ext}" : $base;
}

function uploadMimeFromPath(string $path): string {
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
    ];
    return $map[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** Copies a file that is already on disk into the database. Returns true on success. */
function storeUploadInDb(mysqli $mysqli, string $path): bool {
    $path = normaliseUploadPath($path);
    $abs  = dirname(__DIR__) . '/' . $path;
    if (!is_file($abs) || strlen($path) > 191) return false;
    ensureUploadStoreTables($mysqli);

    try {
        $size = filesize($abs);
        $fh = fopen($abs, 'rb');
        if (!$fh) return false;

        $del = $mysqli->prepare("DELETE FROM upload_file_chunks WHERE path = ?");
        $del->bind_param('s', $path);
        $del->execute();
        $del->close();

        $ins = $mysqli->prepare("INSERT INTO upload_file_chunks (path, seq, data) VALUES (?, ?, ?)");
        $seq = 0;
        $bytes = '';
        $ins->bind_param('sis', $path, $seq, $bytes);
        while (!feof($fh)) {
            $bytes = fread($fh, UPLOAD_STORE_CHUNK);
            if ($bytes === '' || $bytes === false) break;
            $ins->execute();
            $seq++;
        }
        $ins->close();
        fclose($fh);

        $mime = uploadMimeFromPath($path);
        $meta = $mysqli->prepare("REPLACE INTO upload_files (path, mime, size, chunks) VALUES (?, ?, ?, ?)");
        $meta->bind_param('ssii', $path, $mime, $size, $seq);
        $meta->execute();
        $meta->close();
        return true;
    } catch (Throwable $e) {
        error_log('upload store: ' . $e->getMessage());
        return false;
    }
}

/**
 * Makes sure an upload exists on disk, writing it back from the database when
 * it is missing. Returns true when the file is on disk afterwards.
 */
function ensureUploadOnDisk(mysqli $mysqli, string $path): bool {
    $path = normaliseUploadPath($path);
    // Only ever write inside uploads/, and never follow "..".
    if (strpos($path, 'uploads/') !== 0 || strpos($path, '..') !== false) return false;
    $abs = dirname(__DIR__) . '/' . $path;
    if (is_file($abs)) return true;
    ensureUploadStoreTables($mysqli);

    try {
        $meta = $mysqli->prepare("SELECT chunks FROM upload_files WHERE path = ?");
        $meta->bind_param('s', $path);
        $meta->execute();
        $row = $meta->get_result()->fetch_assoc();
        $meta->close();
        if (!$row) return false;

        $dir = dirname($abs);
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $tmp = $abs . '.part' . getmypid();
        $fh = fopen($tmp, 'wb');
        if (!$fh) return false;

        $q = $mysqli->prepare("SELECT data FROM upload_file_chunks WHERE path = ? AND seq = ?");
        for ($seq = 0; $seq < (int)$row['chunks']; $seq++) {
            $q->bind_param('si', $path, $seq);
            $q->execute();
            $chunk = $q->get_result()->fetch_row();
            if (!$chunk) { fclose($fh); @unlink($tmp); $q->close(); return false; }
            fwrite($fh, $chunk[0]);
        }
        $q->close();
        fclose($fh);
        return rename($tmp, $abs) || is_file($abs);
    } catch (Throwable $e) {
        error_log('upload restore: ' . $e->getMessage());
        return false;
    }
}

function deleteStoredUpload(mysqli $mysqli, string $path): void {
    $path = normaliseUploadPath($path);
    ensureUploadStoreTables($mysqli);
    try {
        foreach (['upload_file_chunks', 'upload_files'] as $table) {
            $st = $mysqli->prepare("DELETE FROM {$table} WHERE path = ?");
            $st->bind_param('s', $path);
            $st->execute();
            $st->close();
        }
    } catch (Throwable $e) {
        error_log('upload store delete: ' . $e->getMessage());
    }
}
