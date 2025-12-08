<?php
declare(strict_types=1);

namespace App\Features\Checks;

final class CheckResult
{
    public int $checkId;
    public string $shortVerdict;
    public string $longReport;
    public string $inputCapsule;
    public bool $isScam;
    public bool $isPaid;
    public array $upsellFlags;

    public function __construct(
        int $checkId,
        string $shortVerdict,
        string $longReport,
        string $inputCapsule,
        bool $isScam,
        bool $isPaid,
        array $upsellFlags = []
    ) {
        $this->checkId      = $checkId;
        $this->shortVerdict = $shortVerdict;
        $this->longReport   = $longReport;
        $this->inputCapsule = $inputCapsule;
        $this->isScam       = $isScam;
        $this->isPaid       = $isPaid;
        $this->upsellFlags  = $upsellFlags;
    }
}
