<?php
/**
 * Document upload limits.
 *
 * These are shared by the student upload page (checklist.php) and the upload
 * handler (process_checklist_upload.php) so the browser-side check and the
 * server-side check always agree.
 *
 * Uploaded documents are NOT decided automatically. Every upload is stored as
 * 'pending' and stays that way until a registrar approves it, or rejects it
 * with a written note explaining why.
 */

define('DOC_MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB
define('DOC_ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png']);

// A rejection note must be at least this long, so students get a usable reason.
define('DOC_MIN_FEEDBACK_LENGTH', 5);
