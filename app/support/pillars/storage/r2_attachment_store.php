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
}
