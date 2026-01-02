<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully File Info
 * ============================================================
 * File: app/support/imap_attachments.php
 * Purpose:
 *   Safe IMAP attachment extraction helper for Plainfully email ingestion.
 *
 * Fixes:
 *  - PHP 8.1+ requires imap_fetchstructure() message number to be INT.
 *
 * Behaviour:
 *  - Enforces max_files, max_total_bytes, max_file_bytes
 *  - Sanitises filenames
 *  - Allows only extensions you permit
 *  - Stores attachments either in-memory ('memory') or on disk ('disk')
 *
 * Defaults:
 *  - store: 'disk'
 *  - tmp_dir: /tmp/plainfully (0700)
 * ============================================================
 */

if (!function_exists('pf_imap_extract_attachments')) {

    /**
     * Extract attachments from an IMAP message.
     *
     * @param resource $inbox IMAP stream from imap_open()
     * @param int      $msgno Message number (NOT UID)
     * @param array    $opts  Options:
     *   - max_files (int)
     *   - max_total_bytes (int)
     *   - max_file_bytes (int)
     *   - allow_ext (string[])
     *   - store ('disk'|'memory')
     *   - tmp_dir (string)  Only used when store='disk'
     *
     * @return array{ok:bool, error?:string, attachments:array<int,array<string,mixed>>, total_bytes:int, total_attachments:int}
     */
    function pf_imap_extract_attachments($inbox, int $msgno, array $opts = []): array
    {
        // ----------------------------
        // Limits / allow-list
        // ----------------------------
        $maxFiles      = (int)($opts['max_files'] ?? 5);
        $maxTotalBytes = (int)($opts['max_total_bytes'] ?? (10 * 1024 * 1024)); // 10MB
        $maxFileBytes  = (int)($opts['max_file_bytes'] ?? (5 * 1024 * 1024));  // 5MB

        $allowExt = $opts['allow_ext'] ?? ['pdf','docx','txt','png','jpg','jpeg','webp'];
        $allowExt = array_values(array_unique(array_map('strtolower', (array)$allowExt)));

        $store  = (string)($opts['store'] ?? 'disk'); // 'disk'|'memory'
        $tmpDir = (string)($opts['tmp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'plainfully'));

        $msgno = (int)$msgno;
        if ($msgno <= 0) {
            return [
                'ok' => false,
                'error' => 'Invalid message number.',
                'attachments' => [],
                'total_bytes' => 0,
                'total_attachments' => 0,
            ];
        }

        // ----------------------------
        // IMPORTANT: msgno must be INT
        // ----------------------------
        $structure = @imap_fetchstructure($inbox, $msgno, 0);
        if (!$structure) {
            return ['ok'=>true, 'attachments'=>[], 'total_bytes'=>0, 'total_attachments'=>0];
        }

        $parts = [];
        pf_imap_walk_parts($structure, '', $parts);

        if (empty($parts)) {
            return ['ok'=>true, 'attachments'=>[], 'total_bytes'=>0, 'total_attachments'=>0];
        }

        $attachments = [];
        $totalBytes  = 0;

        foreach ($parts as $p) {
            if (count($attachments) >= $maxFiles) { break; }

            $partNo      = (string)($p['part_no'] ?? '1');
            $mime        = (string)($p['mime'] ?? 'application/octet-stream');
            $encoding    = (int)($p['encoding'] ?? 0);
            $disposition = (string)($p['disposition'] ?? '');

            $name = (string)($p['filename'] ?? '');
            if ($name === '') { $name = (string)($p['name'] ?? 'attachment'); }

            $safeName = pf_imap_sanitise_filename($name);
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));

            if ($ext === '') {
                $extFromMime = pf_imap_ext_from_mime($mime);
                if ($extFromMime !== '') {
                    $safeName .= '.' . $extFromMime;
                    $ext = $extFromMime;
                }
            }

            // Strict allow-list
            if ($ext === '' || !in_array($ext, $allowExt, true)) {
                continue;
            }

            // Fetch + decode part bytes
            $raw = @imap_fetchbody($inbox, $msgno, $partNo, FT_PEEK);
            if (!is_string($raw) || $raw === '') { continue; }

            $bytes = pf_imap_decode_part($raw, $encoding);
            if (!is_string($bytes) || $bytes === '') { continue; }

            $size = strlen($bytes);

            // Enforce limits
            if ($size > $maxFileBytes) { continue; }
            if (($totalBytes + $size) > $maxTotalBytes) { break; }

            $item = [
                'filename'    => $safeName,
                'mime'        => $mime,
                'disposition' => $disposition,
                'part_no'     => $partNo,
                'bytes_len'   => $size,
            ];

            if ($store === 'memory') {
                $item['bytes'] = $bytes;
            } else {
                if (!is_dir($tmpDir)) {
                    @mkdir($tmpDir, 0700, true);
                }
                if (!is_dir($tmpDir) || !is_writable($tmpDir)) {
                    return [
                        'ok' => false,
                        'error' => 'Attachment tmp dir not writable: ' . $tmpDir,
                        'attachments' => [],
                        'total_bytes' => 0,
                        'total_attachments' => 0,
                    ];
                }

                $path = pf_imap_unique_tmp_path($tmpDir, $safeName);
                $ok = @file_put_contents($path, $bytes, LOCK_EX);
                if ($ok === false) {
                    return [
                        'ok' => false,
                        'error' => 'Failed to write attachment to disk.',
                        'attachments' => [],
                        'total_bytes' => 0,
                        'total_attachments' => 0,
                    ];
                }
                @chmod($path, 0600);
                $item['path'] = $path;
            }

            $attachments[] = $item;
            $totalBytes += $size;
        }

        return [
            'ok' => true,
            'attachments' => $attachments,
            'total_bytes' => $totalBytes,
            'total_attachments' => count($attachments),
        ];
    }

    /**
     * Walk IMAP structure and collect leaf parts that look like attachments.
     *
     * @param object $struct
     * @param string $prefix Part number prefix (e.g. "1.2")
     * @param array  $out
     */
    function pf_imap_walk_parts(object $struct, string $prefix, array &$out): void
    {
        // Multipart container
        if (isset($struct->parts) && is_array($struct->parts)) {
            $i = 0;
            foreach ($struct->parts as $part) {
                $i++;
                $partNo = ($prefix === '') ? (string)$i : ($prefix . '.' . $i);
                if (is_object($part)) {
                    pf_imap_walk_parts($part, $partNo, $out);
                }
            }
            return;
        }

        // Leaf part
        $disp = '';
        if (isset($struct->disposition) && is_string($struct->disposition)) {
            $disp = strtolower($struct->disposition);
        }

        $params = pf_imap_collect_params($struct);
        $filename = (string)($params['filename'] ?? '');
        $name     = (string)($params['name'] ?? '');
        $hasName  = ($filename !== '' || $name !== '');

        // Accept:
        // - disposition=attachment
        // - inline + filename
        // - no disposition but filename provided
        $isAttachment = ($disp === 'attachment') || ($disp === 'inline' && $hasName) || ($disp === '' && $hasName);
        if (!$isAttachment) { return; }

        $out[] = [
            'part_no'     => $prefix !== '' ? $prefix : '1',
            'disposition' => $disp,
            'mime'        => pf_imap_mime_from_struct($struct),
            'encoding'    => (int)($struct->encoding ?? 0),
            'filename'    => $filename,
            'name'        => $name,
        ];
    }

    function pf_imap_collect_params(object $struct): array
    {
        $out = [];

        // parameters
        if (isset($struct->parameters) && is_array($struct->parameters)) {
            foreach ($struct->parameters as $p) {
                if (!is_object($p)) { continue; }
                $attr = strtolower((string)($p->attribute ?? ''));
                $val  = (string)($p->value ?? '');
                if ($attr !== '' && $val !== '') {
                    $out[$attr] = pf_imap_decode_mime_header($val);
                }
            }
        }

        // dparameters
        if (isset($struct->dparameters) && is_array($struct->dparameters)) {
            foreach ($struct->dparameters as $p) {
                if (!is_object($p)) { continue; }
                $attr = strtolower((string)($p->attribute ?? ''));
                $val  = (string)($p->value ?? '');
                if ($attr !== '' && $val !== '') {
                    $out[$attr] = pf_imap_decode_mime_header($val);
                }
            }
        }

        return $out;
    }

    function pf_imap_decode_mime_header(string $s): string
    {
        // Best-effort decode for encoded filenames
        if (function_exists('imap_mime_header_decode')) {
            $decoded = @imap_mime_header_decode($s);
            if (is_array($decoded)) {
                $out = '';
                foreach ($decoded as $d) {
                    if (is_object($d) && isset($d->text)) {
                        $out .= (string)$d->text;
                    }
                }
                if ($out !== '') { return $out; }
            }
        }
        return $s;
    }

    function pf_imap_mime_from_struct(object $struct): string
    {
        $primary = [
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'other',
        ];

        $t = (int)($struct->type ?? 7);
        $sub = strtolower((string)($struct->subtype ?? 'octet-stream'));
        $p = $primary[$t] ?? 'application';

        return $p . '/' . ($sub !== '' ? $sub : 'octet-stream');
    }

    function pf_imap_decode_part(string $raw, int $encoding): string
    {
        // 0=7BIT,1=8BIT,2=BINARY,3=BASE64,4=QUOTED-PRINTABLE,5=OTHER
        switch ($encoding) {
            case 3:
                $decoded = base64_decode($raw, true);
                return is_string($decoded) ? $decoded : '';
            case 4:
                return quoted_printable_decode($raw);
            default:
                return $raw;
        }
    }

    function pf_imap_sanitise_filename(string $name): string
    {
        $name = trim($name);
        if ($name === '') { return 'attachment'; }

        // Remove path separators
        $name = str_replace(['\\', '/'], '_', $name);

        // Replace unsafe chars
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'attachment';

        // Avoid dotfiles
        $name = ltrim($name, '.');
        if ($name === '') { $name = 'attachment'; }

        // Limit length
        if (strlen($name) > 120) {
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $base = substr(pathinfo($name, PATHINFO_FILENAME), 0, 100);
            $name = $ext !== '' ? ($base . '.' . $ext) : $base;
        }

        return $name;
    }

    function pf_imap_unique_tmp_path(string $dir, string $filename): string
    {
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);

        $rand = bin2hex(random_bytes(6));
        $safe = $base . '__' . $rand . ($ext !== '' ? ('.' . $ext) : '');

        return $dir . DIRECTORY_SEPARATOR . $safe;
    }

    function pf_imap_ext_from_mime(string $mime): string
    {
        $mime = strtolower(trim($mime));
        $map = [
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];
        return $map[$mime] ?? '';
    }
}
