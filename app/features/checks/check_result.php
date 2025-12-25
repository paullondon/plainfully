<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * Result object returned by CheckEngine.
 *
 * Keep this as a dumb container so controllers/views can safely read it.
 */
final class CheckResult
{
    /** @var int|null Database ID (checks.id) */
    public ?int $id;

    /** @var string Short human verdict */
    public string $shortVerdict;

    /** @var string Capsule summary (safe excerpt) */
    public string $inputCapsule;

    /** @var bool Whether it looks like a scam */
    public bool $isScam;

    /** @var bool Whether this run was treated as paid */
    public bool $isPaid;

    /** @var array<string,mixed> Extra fields for debugging/telemetry */
    public array $meta;

    public function __construct(
        ?int $id,
        string $shortVerdict,
        string $inputCapsule,
        bool $isScam,
        bool $isPaid,
        array $meta = []
    ) {
        $this->id          = $id;
        $this->shortVerdict = $shortVerdict;
        $this->inputCapsule = $inputCapsule;
        $this->isScam       = $isScam;
        $this->isPaid       = $isPaid;
        $this->meta         = $meta;
    }
}
