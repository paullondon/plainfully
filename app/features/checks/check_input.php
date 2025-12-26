<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * Value object representing a single inbound check request
 * (email, sms, web, api).
 *
 * This class MUST be dumb:
 * - no DB access
 * - no AI calls
 * - no side effects
 *
 * It only carries data into CheckEngine.
 */
final class CheckInput
{
    public string $channel;
    public string $sourceIdentifier;
    public string $contentType;
    public string $content;

    public ?string $email;
    public ?string $phone;

    /** @var array<string,mixed>|null */
    public ?array $meta;

    public function __construct(
        string $channel,
        string $sourceIdentifier,
        string $contentType,
        string $content,
        ?string $email = null,
        ?string $phone = null,
        ?array $meta = null
    ) {
        $this->channel          = $channel;
        $this->sourceIdentifier = $sourceIdentifier;
        $this->contentType      = $contentType;
        $this->content          = $content;
        $this->email            = $email;
        $this->phone            = $phone;
        $this->meta             = $meta;
    }

    public function toArray(): array
    {
        return [
            'channel'           => $this->channel,
            'source_identifier' => $this->sourceIdentifier,
            'content_type'      => $this->contentType,
            'content'           => $this->content,
            'email'             => $this->email,
            'phone'             => $this->phone,
            'meta'              => $this->meta,
        ];
    }
}
