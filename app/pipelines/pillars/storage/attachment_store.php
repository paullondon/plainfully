<?php declare(strict_types=1);

namespace App\Pillars\Storage;

use RuntimeException;

interface AttachmentStore
{
    /**
     * Save raw bytes to storage and return a storage key.
     * The key must be stable and safe to store in DB payload_json.
     */
    public function put(string $traceId, string $originalName, string $bytes, string $mime): string;

    /**
     * Retrieve bytes for a stored key.
     */
    public function get(string $key): string;

    /**
     * Best-effort metadata for a stored key.
     */
    public function stat(string $key): array;

    /**
     * Delete a stored object. Must be safe to call repeatedly (idempotent).
     */
    public function delete(string $key): void;

    /**
     * Move a stored object to an OCR staging area (optional debug/visibility).
     */
    public function moveToOcrStaging(string $key): void;

}

final class AttachmentStoreFactory
{

    public static function make(): AttachmentStore
    {
        $driver = strtolower((string)\pf_env('PF_STORAGE_DRIVER', 'local'));

        if ($driver === 'local') {
            $dir = (string)\pf_env('PF_STORAGE_LOCAL_DIR', '');
            if ($dir === '') {
                throw new RuntimeException('PF_STORAGE_LOCAL_DIR missing');
            }
            return new LocalAttachmentStore($dir);
        }

        if ($driver === 'r2') {
            return new R2AttachmentStore(
                (string)\pf_env('PF_R2_ENDPOINT', ''),
                (string)\pf_env('PF_R2_BUCKET', ''),
                (string)\pf_env('PF_R2_ACCESS_KEY', ''),
                (string)\pf_env('PF_R2_SECRET_KEY', '')
            );
        }

        throw new RuntimeException('Unknown PF_STORAGE_DRIVER: ' . $driver);
    }
}
