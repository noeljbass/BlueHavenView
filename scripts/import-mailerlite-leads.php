<?php
declare(strict_types=1);

require_once __DIR__ . '/../mailerlite.php';

$csvPath = $argv[1] ?? (__DIR__ . '/../data/lead-submissions.csv');

if (!is_file($csvPath)) {
    fwrite(STDERR, "Lead CSV not found: {$csvPath}\n");
    exit(1);
}

$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Could not open lead CSV: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($handle);
if ($header === false) {
    fwrite(STDERR, "Lead CSV is empty: {$csvPath}\n");
    exit(1);
}

$processed = 0;
$synced = 0;
$skipped = 0;
$failed = 0;

while (($row = fgetcsv($handle)) !== false) {
    $lead = [];
    foreach ($header as $index => $key) {
        $lead[(string)$key] = (string)($row[$index] ?? '');
    }

    if (!isset($lead['marketing_consent']) || trim($lead['marketing_consent']) === '') {
        $lead['marketing_consent'] = mailerlite_bool_env('MAILERLITE_IMPORT_ASSUME_CONSENT', false) ? 'yes' : 'no';
    }

    $processed++;
    $result = mailerlite_sync_lead($lead);
    $message = mailerlite_result_message($result);

    if ($message === 'synced') {
        $synced++;
    } elseif (str_starts_with($message, 'skipped:')) {
        $skipped++;
    } else {
        $failed++;
    }

    echo "{$processed}. " . (mailerlite_csv_value($lead, 'email') ?: '[no email]') . " - {$message}\n";
}

fclose($handle);

echo "Done. Processed: {$processed}; synced: {$synced}; skipped: {$skipped}; failed: {$failed}.\n";

exit($failed > 0 ? 1 : 0);
