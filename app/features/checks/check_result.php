<?php declare(strict_types=1);

namespace App\Features\Checks;

/**
 * CheckResult (Plainfully v1)
 *
 * A dumb container returned by CheckEngine.
 *
 * NOTE:
 * You MUST deploy this alongside the updated CheckEngine, otherwise you will see
 * constructor argument type errors.
 */
final class CheckResult
{
    /** @var int|null Database ID (checks.id) */
    public ?int $id;

    /** @var string ok|limited|unreadable */
    public string $status;

    /** @var string Headline (clarity-led unless high risk) */
    public string $headline;

    /** @var string low|medium|high|unable */
    public string $scamRiskLevel;

    /** @var string External channel risk line (e.g. "Scam risk level: Low (checked)") */
    public string $externalRiskLine;

    /** @var string External channel topic line (single short sentence; no reasons) */
    public string $externalTopicLine;

    /** @var string Web: what the message says */
    public string $webWhatTheMessageSays;

    /** @var string Web: what it's asking for */
    public string $webWhatItsAskingFor;

    /** @var string Web: "Scam risk level: <...>" */
    public string $webScamLevelLine;

    /** @var string Web: low-risk reinforcement or generic note */
    public string $webLowRiskNote;

    /** @var string Web: explanation for why the risk level was given */
    public string $webScamExplanation;

    /** @var bool Whether this run was treated as paid */
    public bool $isPaid;

    /**
     * Backwards-compat fields (older templates may read these).
     * These are derived from the new fields.
     */
    public string $shortVerdict;
    public string $inputCapsule;
    public bool $isScam;

    /** @var array<string,mixed> Extra fields for debugging/telemetry */
    public array $meta;

    /** @var string|null Raw JSON returned by the AI (for storage/rendering) */
    public ?string $rawJson;

    public function __construct(
        ?int $id,
        string $status,
        string $headline,
        string $scamRiskLevel,
        string $externalRiskLine,
        string $externalTopicLine,
        string $webWhatTheMessageSays,
        string $webWhatItsAskingFor,
        string $webScamLevelLine,
        string $webLowRiskNote,
        string $webScamExplanation,
        bool $isPaid,
        array $meta = [],
        ?string $rawJson = null
    ) {
        $this->id                   = $id;
        $this->status               = $status;
        $this->headline             = $headline;
        $this->scamRiskLevel        = $scamRiskLevel;
        $this->externalRiskLine     = $externalRiskLine;
        $this->externalTopicLine    = $externalTopicLine;
        $this->webWhatTheMessageSays = $webWhatTheMessageSays;
        $this->webWhatItsAskingFor  = $webWhatItsAskingFor;
        $this->webScamLevelLine     = $webScamLevelLine;
        $this->webLowRiskNote       = $webLowRiskNote;
        $this->webScamExplanation   = $webScamExplanation;
        $this->isPaid               = $isPaid;
        $this->meta                 = $meta;
        $this->rawJson              = $rawJson;

        // Derived compatibility fields
        $this->shortVerdict = $headline;
        $this->inputCapsule = $externalTopicLine;
        $this->isScam        = ($scamRiskLevel === 'high');
    }
}
