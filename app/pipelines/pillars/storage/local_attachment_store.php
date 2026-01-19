<?php declare(strict_types=1);

namespace App\Pillars\Storage;

use RuntimeException;

final class LocalAttachmentStore implements AttachmentStore
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    /**
     * Move an attachment into an OCR staging folder (debug/visibility).
     * This keeps the bytes available briefly for the OCR step before deletion.
     *
     * NOTE:
     * - This is optional behaviour, gated by PF_OCR_STAGING=1.
     * - Uses rename() for speed (same filesystem).
     */
    public function moveToOcrStaging(string $key): void
    {
        // Only handle local keys
        if (!str_starts_with($key, 'local:')) {
            throw new \RuntimeException('moveToOcrStaging only supports local: keys');
        }

        $src = substr($key, 6); // strip "local:"
        if ($src === '' || !is_file($src)) {
            // Idempotent: if already gone, treat as success
            return;
        }

        // Put alongside attachments, under /ocr_pending/
        $dst = str_replace('/attachments/', '/attachments/ocr_pending/', $src);

        $dir = dirname($dst);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create OCR staging dir: ' . $dir);
            }
        }

        if (!@rename($src, $dst)) {
            throw new \RuntimeException('Failed to move to OCR staging');
        }
    }
    
    public function put(string $traceId, string $originalName, string $bytes, string $mime): string
    {
        $safeName = $this->safeFilename($originalName);
        $subdir = $this->baseDir . '/' . substr($traceId, 0, 2) . '/' . substr($traceId, 2, 2) . '/' . $traceId;

        if (!is_dir($subdir) && !@mkdir($subdir, 0750, true)) {
            throw new RuntimeException('Failed to create attachment dir');
        }

        $id = bin2hex(random_bytes(8));
        $path = $subdir . '/' . $id . '_' . $safeName;

        if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write attachment');
        }

        // Store key as "local:<absolute path>" to make driver swap explicit
        return 'local:' . $path;
    }

    public function get(string $key): string
    {
        $path = $this->parseKey($key);
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('Attachment read failed');
        }
        return $bytes;
    }

    public function stat(string $key): array
    {
        $path = $this->parseKey($key);
        return [
            'driver' => 'local',
            'path'   => $path,
            'size'   => is_file($path) ? (int)filesize($path) : 0,
            'mtime'  => is_file($path) ? (int)filemtime($path) : 0,
        ];
    }

    public function delete(string $key): void
    {
        $path = $this->parseKey($key);

        // Idempotent: if it’s already gone, that’s fine.
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function parseKey(string $key): string
    {
        if (strpos($key, 'local:') !== 0) {
            throw new RuntimeException('Invalid local key');
        }
        $path = substr($key, 6);

        // Security: ensure it lives under baseDir
        $realBase = realpath($this->baseDir) ?: $this->baseDir;
        $realPath = realpath($path);

        // If the file was deleted already, realpath() returns false.
        // In that case, we still validate by string prefix check as a fallback.
        if ($realPath === false) {
            $normBase = rtrim($realBase, '/') . '/';
            $normPath = rtrim($path, '/');
            if (strpos($normPath, $normBase) !== 0) {
                throw new RuntimeException('Invalid local path');
            }
            return $normPath;
        }

        if (strpos($realPath, $realBase) !== 0) {
            throw new RuntimeException('Invalid local path');
        }

        return $realPath;
    }

    private function safeFilename(string $name): string
    {
        $name = trim($name);
        if ($name === '') $name = 'file.bin';

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'file.bin';
        $name = ltrim($name, '._'); // avoid hidden files
        if ($name === '') $name = 'file.bin';

        return $name;
    }
}
