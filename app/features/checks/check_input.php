<?php
declare(strict_types=1);

namespace App\Features\Checks;

final class CheckInput
{
    public string $channel;
    public string $sourceIdentifier;
    public string $contentType;
    public string $rawContent;
    public ?string $email;
    public ?string $phone;
    public ?string $providerUserId;

    public function __construct(
        string $channel,
        string $sourceIdentifier,
        string $contentType,
        string $rawContent,
        ?string $email = null,
        ?string $phone = null,
        ?string $providerUserId = null
    ) {
        $this->channel          = trim($channel);
        $this->sourceIdentifier = trim($sourceIdentifier);
        $this->contentType      = trim($contentType);
        $this->rawContent       = $rawContent;
        $this->email            = $email ? strtolower(trim($email)) : null;
        $this->phone            = $phone ?: null;
        $this->providerUserId   = $providerUserId ?: null;
    }
}
