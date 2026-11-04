<?php
// Pure helpers; never deliver mail or make network requests.
function bh_valid_oppref($value): string
{
    return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $value) === 1 ? $value : '';
}
function bh_append_attribution(string $path, array $lead): bool
{
    $reference = bh_valid_oppref($lead['oppref'] ?? '');
    if ($reference === '') return true; // Nothing to persist.
    $id = $lead['lead_id'] ?? '';
    $time = $lead['submitted_at_iso'] ?? '';
    if (!is_string($id) || preg_match('/^BH-\d{8}-[A-F0-9]{8}$/D', $id) !== 1 ||
        !is_string($time) || strlen($time) > 40 || strtotime($time) === false) return false;
    $header = ['submitted_at', 'lead_id', 'oppref'];
    $mask = umask(0077);
    $handle = @fopen($path, 'c+b');
    umask($mask);
    if (!$handle) return false;
    try {
        if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) return false;
        $size = fstat($handle)['size'];
        if ($size > 0 && fgetcsv($handle) !== $header) return false;
        fseek($handle, 0, SEEK_END);
        if ($size === 0 && fputcsv($handle, $header) === false) return false;
        return fputcsv($handle, [$time, $id, $reference]) !== false && fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
