<?php declare(strict_types=1);

namespace App\Pillars\Storage;

use RuntimeException;

final class R2AttachmentStore implements AttachmentStore
{
    public function __construct(
        private string $endpoint,
        private string $bucket,
        private string $accessKey,
        private string $secretKey
    ) {}

    public function put(string $traceId, string $originalName, string $bytes, string $mime): string
    {
        throw new RuntimeException('R2 storage not enabled yet (implement later)');
    }

    public function get(string $key): string
    {
        throw new RuntimeException('R2 storage not enabled yet (implement later)');
    }

    public function stat(string $key): array
    {
        return ['driver' => 'r2', 'key' => $key];
    }

    public function delete(string $key): void
    {
        // Later: delete object from R2.
        // For now: safe no-op so code won’t break if someone switches driver accidentally.
    }
    public function moveToOcrStaging(string $key): void
    {
        // MVP: no-op (or throw). For R2 we’ll implement copy+delete later.
        $this->delete($key);
    }
}
