<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Attachment Cleanup Cron (GDPR safety net)
 * ============================================================
 * Why:
 *  - If OCR/processing crashes mid-run, attachment files must not linger.
 *
 * What it does:
 *  - Deletes files under PF_STORAGE_LOCAL_DIR older than PF_ATTACHMENTS_MAX_AGE_SECONDS
 *
 * Env:
 *  - PF_STORAGE_DRIVER=local
 *  - PF_STORAGE_LOCAL_DIR=/.../httpdocs/storage/attachments
 *  - PF_ATTACHMENTS_MAX_AGE_SECONDS=3600 (default 1 hour)
 */

require_once __DIR__ . '/../app/bootstrap.php';

$driver = strtolower((string)pf_env('PF_STORAGE_DRIVER', 'local'));
if ($driver !== 'local') {
    echo "Cleanup skipped (PF_STORAGE_DRIVER={$driver})\n";
    exit(0);
}

$baseDir = rtrim((string)pf_env('PF_STORAGE_LOCAL_DIR', ''), '/');
if ($baseDir === '' || !is_dir($baseDir)) {
    fwrite(STDERR, "Cleanup failed: PF_STORAGE_LOCAL_DIR missing/invalid\n");
    exit(1);
}

$maxAge = (int)(pf_env('PF_ATTACHMENTS_MAX_AGE_SECONDS', '3600') ?? '3600');
if ($maxAge < 60) $maxAge = 60;

$now = time();
$deleted = 0;
$scanned = 0;

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($it as $fileInfo) {
    $path = (string)$fileInfo->getPathname();
    $scanned++;

    try {
        if ($fileInfo->isFile()) {
            $mtime = (int)$fileInfo->getMTime();
            if (($now - $mtime) >= $maxAge) {
                if (@unlink($path)) {
                    $deleted++;
                }
            }
        } elseif ($fileInfo->isDir()) {
            // Remove empty dirs to keep things tidy
            @rmdir($path);
        }
    } catch (Throwable $e) {
        // ignore per-file failures
    }
}

pf_log('info', 'Attachment cleanup complete', [
    'base_dir' => $baseDir,
    'max_age_s' => $maxAge,
    'scanned' => $scanned,
    'deleted' => $deleted,
]);

echo "Attachment cleanup complete. deleted={$deleted}\n";
