<?php declare(strict_types=1);

namespace App\Support\Ingestion;

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/ingestion/ingest_attachments.php
 * Purpose:
 *   Attachment ingestion + text extraction for inbound emails (MVP).
 *
 * What this file does:
 *   - Walks IMAP MIME parts
 *   - Extracts attachments (allowlist only)
 *   - Enforces limits (count + size)
 *   - Extracts text where possible:
 *       * .txt  -> direct
 *       * .docx -> ZipArchive + strip tags
 *       * .pdf  -> pdftotext if available (best-effort)
 *       * images -> NO OCR here (marks needs_ocr=true)
 *
 * Trace support:
 *   - If app/support/trace.php is loaded and tracing is enabled, this file
 *     emits safe stage=attachments events (no raw bytes stored unless deep).
 *
 * Security principles:
 *   - Deny-by-default file types (allowlist)
 *   - No archives (.zip) in MVP (blocks zip-bombs)
 *   - No macro formats (.docm, .xlsm, etc.)
 *   - Never executes user content
 *   - Uses temp files + escapeshellarg for pdf extraction
 * ============================================================
 */

/**
 * Extract attachments for an IMAP message.
 *
 * @param mixed $inbox IMAP stream resource
 * @param int $msgno IMAP message number
 * @param array $opts Limits and allowlist options
 * @param array $trace Optional trace context:
 *   [
 *     'pdo' => \PDO|null,
 *     'trace_id' => string,
 *     'queue_id' => int|null
 *   ]
 *
 * @return array{
 *   ok:bool,
 *   reason?:string,
 *   attachments:array<int,array<string,mixed>>,
 *   total_bytes:int,
 *   total_attachments:int
 * }
 */
function pf_imap_extract_attachments($inbox, int $msgno, array $opts = [], array $trace = []): array
{
    $maxFiles      = (int)($opts['max_files'] ?? 5);
    $maxTotalBytes = (int)($opts['max_total_bytes'] ?? (10 * 1024 * 1024)); // 10MB
    $maxFileBytes  = (int)($opts['max_file_bytes'] ?? (5 * 1024 * 1024));  // 5MB

    $allowExt = $opts['allow_ext'] ?? ['pdf','docx','txt','png','jpg','jpeg','webp'];
    $allowExt = array_values(array_unique(array_map('strtolower', (array)$allowExt)));

    $pdo     = (isset($trace['pdo']) && $trace['pdo'] instanceof \PDO) ? $trace['pdo'] : null;
    $traceId = is_string($trace['trace_id'] ?? null) ? (string)$trace['trace_id'] : '';
    $queueId = $trace['queue_id'] ?? null;

    // ------------------------------------------------------------
    // Trace: start
    // ------------------------------------------------------------
    if (function_exists('pf_trace')) {
        pf_trace($pdo, $traceId, 'info', 'attachments', 'start', 'Attachment scan starting', [
            'msgno' => $msgno,
            'max_files' => $maxFiles,
            'max_total_bytes' => $maxTotalBytes,
            'max_file_bytes' => $maxFileBytes,
            'allow_ext' => $allowExt,
        ], $queueId, null);
    }

    $structure = @imap_fetchstructure($inbox, (string)$msgno, 0);
    if (!$structure) {
        if (function_exists('pf_trace')) {
            pf_trace($pdo, $traceId, 'info', 'attachments', 'no_structure', 'No IMAP structure found', [
                'msgno' => $msgno,
            ], $queueId, null);
        }
        return ['ok'=>true, 'attachments'=>[], 'total_bytes'=>0, 'total_attachments'=>0];
    }

    $parts = [];
    pf__imap_walk_parts($structure, '', $parts);

    $attachments = [];
    $totalBytes = 0;

    foreach ($parts as $p) {
        if (!isset($p['partno'], $p['is_attachment']) || $p['is_attachment'] !== true) {
            continue;
        }

        if (count($attachments) >= $maxFiles) {
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'warn', 'attachments', 'limit_files', 'Too many attachments', [
                    'max_files' => $maxFiles,
                    'seen' => count($attachments),
                ], $queueId, null);
            }
            return ['ok'=>false,'reason'=>'too_many_attachments','attachments'=>[],'total_bytes'=>$totalBytes,'total_attachments'=>count($attachments)];
        }

        $filename = (string)($p['filename'] ?? '');
        $mime     = (string)($p['mime'] ?? 'application/octet-stream');
        $partno   = (string)$p['partno'];

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' && isset($p['suggested_ext'])) {
            $ext = (string)$p['suggested_ext'];
        }

        if ($ext === '' || !in_array($ext, $allowExt, true)) {
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'warn', 'attachments', 'unsupported_type', 'Unsupported attachment type', [
                    'filename' => $filename,
                    'mime' => $mime,
                    'ext' => $ext,
                ], $queueId, null);
            }
            return ['ok'=>false,'reason'=>'unsupported_attachment_type','attachments'=>[],'total_bytes'=>$totalBytes,'total_attachments'=>count($attachments)];
        }

        $raw = (string)@imap_fetchbody($inbox, (string)$msgno, $partno, FT_PEEK);
        if ($raw === '') {
            $raw = (string)@imap_fetchbody($inbox, (string)$msgno, $partno . '.0', FT_PEEK);
        }

        $decoded = pf__imap_decode_part($raw, (int)($p['encoding'] ?? 0));

        $bytes = strlen($decoded);
        if ($bytes > $maxFileBytes) {
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'warn', 'attachments', 'file_too_large', 'Attachment too large', [
                    'filename' => $filename,
                    'bytes' => $bytes,
                    'max_file_bytes' => $maxFileBytes,
                ], $queueId, null);
            }
            return ['ok'=>false,'reason'=>'attachment_too_large','attachments'=>[],'total_bytes'=>$totalBytes,'total_attachments'=>count($attachments)];
        }

        $totalBytes += $bytes;
        if ($totalBytes > $maxTotalBytes) {
            if (function_exists('pf_trace')) {
                pf_trace($pdo, $traceId, 'warn', 'attachments', 'total_too_large', 'Attachments total too large', [
                    'filename' => $filename,
                    'this_bytes' => $bytes,
                    'running_total_bytes' => $totalBytes,
                    'max_total_bytes' => $maxTotalBytes,
                ], $queueId, null);
            }
            return ['ok'=>false,'reason'=>'attachments_total_too_large','attachments'=>[],'total_bytes'=>$totalBytes,'total_attachments'=>count($attachments)];
        }

        $kind = pf__kind_from_ext($ext);
        $extracted = '';
        $method = 'none';
        $needsOcr = false;
        $notes = '';

        try {
            if ($kind === 'txt') {
                $extracted = pf__cap_text($decoded, 200000);
                $method = 'txt';
            } elseif ($kind === 'docx') {
                $extracted = pf__extract_docx_text($decoded);
                $extracted = pf__cap_text($extracted, 200000);
                $method = $extracted !== '' ? 'docx' : 'none';
                if ($method === 'none') { $notes = 'Could not extract DOCX text.'; }
            } elseif ($kind === 'pdf') {
                $extracted = pf__extract_pdf_text($decoded);
                $extracted = pf__cap_text($extracted, 200000);
                $method = $extracted !== '' ? 'pdf_text' : 'none';
                if ($method === 'none') { $notes = 'No PDF text found (may be scanned or protected).'; }
            } elseif ($kind === 'image') {
                $needsOcr = true;
                $notes = 'Image received; OCR not enabled in this MVP.';
            }
        } catch (\Throwable $e) {
            $notes = 'Extraction failed: ' . $e->getMessage();
        }

        // ------------------------------------------------------------
        // Trace: per-file result (safe by default; full text only in deep)
        // ------------------------------------------------------------
        if (function_exists('pf_trace')) {
            $meta = [
                'filename' => $filename !== '' ? $filename : ('attachment.' . $ext),
                'mime' => $mime,
                'ext' => $ext,
                'kind' => $kind,
                'bytes' => $bytes,
                'extract_method' => $method,
                'needs_ocr' => $needsOcr,
                'notes' => $notes,
            ];

            // Deep mode: include extracted text (capped); otherwise, include only length
            if (function_exists('pf_trace_deep') && pf_trace_deep()) {
                $meta['extracted_text'] = $extracted;
            } else {
                $meta['extracted_len'] = strlen($extracted);
            }

            pf_trace($pdo, $traceId, 'info', 'attachments', 'file_processed', 'Attachment processed', $meta, $queueId, null);
        }

        $attachments[] = [
            'filename' => $filename !== '' ? $filename : ('attachment.' . $ext),
            'mime' => $mime,
            'bytes' => $bytes,
            'kind' => $kind,
            'extracted_text' => $extracted,
            'extract_method' => $method,
            'needs_ocr' => $needsOcr,
            'notes' => $notes,
        ];
    }

    if (function_exists('pf_trace')) {
        pf_trace($pdo, $traceId, 'info', 'attachments', 'done', 'Attachment scan complete', [
            'total_attachments' => count($attachments),
            'total_bytes' => $totalBytes,
        ], $queueId, null);
    }

    return ['ok'=>true,'attachments'=>$attachments,'total_bytes'=>$totalBytes,'total_attachments'=>count($attachments)];
}

/**
 * Walk IMAP part tree and collect leaf parts.
 * This keeps parsing logic in one place for easier debugging.
 */
function pf__imap_walk_parts($structure, string $prefix, array &$out): void
{
    if (!empty($structure->parts) && is_array($structure->parts)) {
        $i = 1;
        foreach ($structure->parts as $part) {
            $partno = $prefix === '' ? (string)$i : ($prefix . '.' . $i);
            pf__imap_walk_parts($part, $partno, $out);
            $i++;
        }
        return;
    }

    $filename = '';
    $isAttachment = false;

    if (!empty($structure->dparameters) && is_array($structure->dparameters)) {
        foreach ($structure->dparameters as $dp) {
            if (isset($dp->attribute) && strtolower((string)$dp->attribute) === 'filename') {
                $filename = (string)($dp->value ?? '');
                $isAttachment = true;
            }
        }
    }
    if ($filename === '' && !empty($structure->parameters) && is_array($structure->parameters)) {
        foreach ($structure->parameters as $pp) {
            if (isset($pp->attribute) && strtolower((string)$pp->attribute) === 'name') {
                $filename = (string)($pp->value ?? '');
                $isAttachment = true;
            }
        }
    }

    $disp = isset($structure->disposition) ? strtolower((string)$structure->disposition) : '';
    if (in_array($disp, ['attachment','inline'], true) && $filename !== '') {
        $isAttachment = true;
    }

    $mime = pf__imap_guess_mime($structure);
    $suggestedExt = pf__ext_from_mime($mime);

    $out[] = [
        'partno' => $prefix !== '' ? $prefix : '1',
        'is_attachment' => $isAttachment,
        'filename' => $filename,
        'mime' => $mime,
        'encoding' => (int)($structure->encoding ?? 0),
        'suggested_ext' => $suggestedExt,
    ];
}

/**
 * Best-effort MIME guess from IMAP structure fields.
 */
function pf__imap_guess_mime($structure): string
{
    $primary = (int)($structure->type ?? 0);
    $sub = strtolower((string)($structure->subtype ?? ''));

    $map = [
        0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
        4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'
    ];

    $p = $map[$primary] ?? 'application';
    if ($sub === '') { return $p . '/octet-stream'; }
    return $p . '/' . $sub;
}

/**
 * Decode IMAP body based on encoding.
 */
function pf__imap_decode_part(string $raw, int $encoding): string
{
    $decoded = match ($encoding) {
        3 => base64_decode($raw, true),
        4 => quoted_printable_decode($raw),
        default => $raw,
    };
    return is_string($decoded) ? $decoded : '';
}

/**
 * Map file extension to internal "kind" for extraction rules.
 */
function pf__kind_from_ext(string $ext): string
{
    return match (strtolower($ext)) {
        'pdf' => 'pdf',
        'docx' => 'docx',
        'txt' => 'txt',
        'png','jpg','jpeg','webp' => 'image',
        default => 'other',
    };
}

/**
 * Suggest extension when filename doesn't provide one.
 */
function pf__ext_from_mime(string $mime): string
{
    $m = strtolower($mime);
    return match (true) {
        str_contains($m, 'pdf') => 'pdf',
        str_contains($m, 'wordprocessingml') => 'docx',
        str_contains($m, 'text/plain') => 'txt',
        str_contains($m, 'image/jpeg') => 'jpg',
        str_contains($m, 'image/png') => 'png',
        str_contains($m, 'image/webp') => 'webp',
        default => '',
    };
}

/**
 * Cap text to prevent storing or processing enormous payloads.
 */
function pf__cap_text(string $s, int $maxChars): string
{
    $s = trim($s);
    if ($s === '') { return ''; }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($s, 'UTF-8') <= $maxChars) { return $s; }
        return rtrim(mb_substr($s, 0, $maxChars, 'UTF-8'));
    }

    if (strlen($s) <= $maxChars) { return $s; }
    return rtrim(substr($s, 0, $maxChars));
}

/**
 * DOCX text extraction (best-effort).
 * Uses ZipArchive from core PHP (no composer).
 */
function pf__extract_docx_text(string $docxBytes): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'pf_docx_');
    if ($tmp === false) { return ''; }

    try {
        file_put_contents($tmp, $docxBytes);

        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) { return ''; }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!is_string($xml) || $xml === '') { return ''; }

        $xml = str_replace(['</w:p>','<w:br/>','<w:tab/>'], ["\n","\n","\t"], $xml);
        $text = strip_tags($xml);

        $text = preg_replace("/[ \t]+\n/", "\n", (string)$text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);

        return trim((string)$text);
    } catch (\Throwable $e) {
        return '';
    } finally {
        @unlink($tmp);
    }
}

/**
 * PDF text extraction (best-effort).
 * Requires `pdftotext` available on the server PATH.
 */
function pf__extract_pdf_text(string $pdfBytes): string
{
    // Quick encrypted PDF heuristic (avoid hanging tools)
    if (stripos($pdfBytes, '/Encrypt') !== false) {
        return '';
    }

    $pdftotext = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($pdftotext === '') {
        return '';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pf_pdf_');
    if ($tmp === false) { return ''; }

    try {
        file_put_contents($tmp, $pdfBytes);

        $cmd = escapeshellcmd($pdftotext) . ' -q -nopgbrk ' . escapeshellarg($tmp) . ' -';
        $out = (string)@shell_exec($cmd);

        $out = trim($out);
        if ($out === '' || strlen($out) < 20) { return ''; }

        return $out;
    } catch (\Throwable $e) {
        return '';
    } finally {
        @unlink($tmp);
    }
}
