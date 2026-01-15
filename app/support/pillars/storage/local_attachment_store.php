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

    private function parseKey(string $key): string
    {
        if (strpos($key, 'local:') !== 0) {
            throw new RuntimeException('Invalid local key');
        }
        $path = substr($key, 6);

        // Security: ensure it lives under baseDir
        $realBase = realpath($this->baseDir) ?: $this->baseDir;
        $realPath = realpath($path);
        if ($realPath === false || strpos($realPath, $realBase) !== 0) {
            throw new RuntimeException('Invalid local path');
        }

        return $realPath;
    }

    private function safeFilename(string $name): string
    {
        $name = trim($name);
        if ($name === '') $name = 'file.bin';

        // Keep it boring + safe
        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? 'file.bin';
        $name = ltrim($name, '._'); // avoid hidden files
        if ($name === '') $name = 'file.bin';

        return $name;
    }
}
