<?php declare(strict_types=1);

namespace App\Pipelines\Process;

use Throwable;

final class Processor
{
    /**
     * Run OCR stub (if attachments) + sanitise, then produce a result block.
     * Returns:
     *  - updated_payload (evidence package after OCR/sanitise, with deletions applied)
     *  - result (for outbound payload)
     *  - text_preview (safe preview)
     */
    public function run(array $payload, string $traceId, string $inChannel): array
    {
        // Ensure arrays
        if (!isset($payload['text_parts']) || !is_array($payload['text_parts'])) $payload['text_parts'] = [];
        if (!isset($payload['ocr_text_parts']) || !is_array($payload['ocr_text_parts'])) $payload['ocr_text_parts'] = [];
        if (!isset($payload['attachments']) || !is_array($payload['attachments'])) $payload['attachments'] = [];

        // Stage
        $stage = (string)($payload['stage'] ?? 'ingested');
        if ($stage === '') $stage = 'ingested';

        // OCR stub only once
        if ($stage === 'ingested' && $this->hasOcrEligibleAttachments($payload)) {
            $payload = $this->ocrStubAndDeleteEphemeral($payload, $traceId);
            $payload['stage'] = 'ocr_done';
            $stage = 'ocr_done';
        }

        // Sanitise once
        if ($stage === 'ingested' || $stage === 'ocr_done') {
            $payload = $this->sanitise($payload);
            $payload['stage'] = 'sanitised';
        }

        $preview = (string)($payload['sanitised_text'] ?? '');
        $preview = $preview !== '' ? mb_substr($preview, 0, 280) : '';

        return [
            'updated_payload' => $payload,
            'text_preview'    => $preview,
            'result'          => [
                'status'       => 'processed-placeholder',
                'message'      => 'Processed (OCR stub + sanitise complete). AI not wired yet.',
                'processed_at' => gmdate('c'),
            ],
        ];
    }

    private function hasOcrEligibleAttachments(array $payload): bool
    {
        $atts = $payload['attachments'] ?? [];
        if (!is_array($atts) || $atts === []) return false;

        foreach ($atts as $a) {
            if (!is_array($a)) continue;
            $mime = strtolower((string)($a['mime'] ?? ''));
            if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
                return true;
            }
        }
        return false;
    }

    private function ocrStubAndDeleteEphemeral(array $payload, string $traceId): array
    {
        $mode = strtolower((string)\pf_env('PF_ATTACHMENTS_MODE', 'ephemeral')); // ephemeral|retain
        $doDelete = ($mode !== 'retain');

        if ((string)pf_env('PF_WORKER_DEBUG', '0') === '1') {
            pf_log('debug', 'Worker class_exists checks', [
                'Processor' => class_exists('\\App\\Pipelines\\Process\\Processor'),
                'Factory'   => class_exists('\\App\\Pillars\\Storage\\AttachmentStoreFactory'),
            ]);
        }

        $store = \App\Pipelines\Pillars\Storage\AttachmentStoreFactory::make();

        $atts = $payload['attachments'];
        foreach ($atts as $idx => $a) {
            if (!is_array($a)) continue;

            $name = (string)($a['name'] ?? ('file_' . $idx));
            $mime = strtolower((string)($a['mime'] ?? ''));
            $key  = (string)($a['store_key'] ?? '');

            $eligible = (str_starts_with($mime, 'image/') || $mime === 'application/pdf');
            if (!$eligible) continue;

            $payload['ocr_text_parts'][] = "[OCR_STUB] Extracted text placeholder for '{$name}' ({$mime}).";

            if ($doDelete && $key !== '' && $key !== '[deleted]') {
                try {
                    $store->delete($key);

                    $a['store_key']   = '[deleted]';
                    $a['deleted_at']  = gmdate('c');
                    $a['delete_mode'] = 'ephemeral';
                    $atts[$idx] = $a;
                } catch (Throwable $e) {
                    \pf_log('error', 'OCR stub delete failed', [
                        'trace_id' => $traceId,
                        'name'     => $name,
                        'err'      => $e->getMessage(),
                    ]);
                }
            }
        }

        $payload['attachments'] = $atts;
        return $payload;
    }

    private function sanitise(array $payload): array
    {
        $textParts = $payload['text_parts'];
        $ocrParts  = $payload['ocr_text_parts'];

        $combined = trim(implode("\n\n", array_filter(array_map(
            static fn($v): string => is_string($v) ? trim($v) : '',
            array_merge($textParts, $ocrParts)
        ), static fn(string $s): bool => $s !== '')));

        $san = $combined;
        $san = preg_replace('/[^\P{C}\n\t]/u', '', $san) ?? $san;
        $san = preg_replace("/\r\n|\r/", "\n", $san) ?? $san;
        $san = preg_replace("/\n{3,}/", "\n\n", $san) ?? $san;
        $san = trim($san);

        $max = (int)(\pf_env('PF_MAX_TEXT_CHARS', '60000') ?? '60000');
        if ($max < 5000) $max = 5000;
        if (mb_strlen($san) > $max) $san = mb_substr($san, 0, $max);

        $payload['combined_text']  = $combined;
        $payload['sanitised_text'] = $san;

        return $payload;
    }
}
