<?php
declare(strict_types=1);

// B is an isolated delivery test, never a security-provider bypass.
function bh_delivery_requested(array $server): bool
{
    foreach (array_keys($server) as $key) {
        if (str_starts_with((string)$key, 'HTTP_X_BLUEHAVEN_DELIVERY_')) { return true; }
    }
    return false;
}

function bh_delivery_config(): array
{
    $path = __DIR__ . '/data/contact-delivery-test-config.php';
    $config = is_file($path) ? require $path : [];
    return is_array($config) ? $config : [];
}

function bh_delivery_canonical(array $form): string
{
    ksort($form, SORT_STRING);
    $pairs = [];
    foreach ($form as $key => $value) {
        if (!is_string($value)) { throw new InvalidArgumentException('Non-scalar field'); }
        $pairs[] = bin2hex((string)$key) . '=' . bin2hex($value);
    }
    return implode("\n", $pairs);
}

function bh_delivery_authorize(array $form, array $server): array
{
    $fail = ['ok' => false, 'status' => 'delivery_authorization_failed'];
    $config = bh_delivery_config();
    if (!is_string($config['secret'] ?? null) || strlen($config['secret']) < 32
        || !is_int($config['enabled_until'] ?? null) || $config['enabled_until'] < time()
        || ($config['email'] ?? '') !== 'put2practice@gmail.com'
        || ($config['notification_recipient'] ?? '') !== 'put2practice@gmail.com'
        || !is_string($config['phone'] ?? null) || !preg_match('/^\+1\d{10}$/', $config['phone'])) { return $fail; }
    foreach (array_keys($server) as $key) {
        if (str_starts_with((string)$key, 'HTTP_X_BLUEHAVEN_QA_')) { return $fail; }
    }
    foreach (['qa_signature', 'qa_timestamp', 'qa_nonce', 'qa_payload_hash'] as $key) {
        if (array_key_exists($key, $form)) { return $fail; }
    }
    if (($server['REQUEST_METHOD'] ?? '') !== 'POST' || ($server['REQUEST_URI'] ?? '') !== '/contact.php'
        || ($server['HTTP_HOST'] ?? '') !== 'bluehavenview.com') { return $fail; }
    $timestamp = $server['HTTP_X_BLUEHAVEN_DELIVERY_TIMESTAMP'] ?? '';
    $nonce = $server['HTTP_X_BLUEHAVEN_DELIVERY_NONCE'] ?? '';
    $run = $server['HTTP_X_BLUEHAVEN_DELIVERY_RUN_ID'] ?? '';
    $signature = $server['HTTP_X_BLUEHAVEN_DELIVERY_SIGNATURE'] ?? '';
    if (!is_string($timestamp) || !preg_match('/^\d{10}$/D', $timestamp)
        || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
        || !is_string($run) || !preg_match('/^BHQA-\d{8}-[A-Z0-9]{6}$/D', $run)
        || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        || (int)$timestamp < time() - 120 || (int)$timestamp > time() + 15) { return $fail; }
    $required = ['qa_mode' => 'developer', 'qa_run_id' => $run, 'utm_source' => 'qa',
        'utm_medium' => 'internal', 'utm_content' => $run, 'marketing_consent' => '0',
        'email' => $config['email'], 'phone' => $config['phone']];
    foreach ($required as $key => $value) { if (($form[$key] ?? null) !== $value) { return $fail; } }
    if (!str_starts_with((string)($form['first_name'] ?? ''), 'FFC QA TEST')) { return $fail; }
    foreach (['gclid', 'gbraid', 'wbraid', 'fbclid'] as $key) {
        if (($form[$key] ?? '') !== '') { return $fail; }
    }
    $payloadHash = hash('sha256', bh_delivery_canonical($form));
    $canonical = implode("\n", ['bluehaven-delivery-v1', 'method=POST', 'path=/contact.php',
        'host=bluehavenview.com', 'timestamp=' . $timestamp, 'nonce=' . $nonce,
        'run_id=' . $run, 'payload=' . $payloadHash]);
    if (!hash_equals(hash_hmac('sha256', $canonical, $config['secret']), $signature)) { return $fail; }
    $root = bh_security_root_dir();
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) { return $fail; }
    // Permanent run reservations, bounded by private config lifetime. Never prune/release a consumed run.
    $path = bh_security_root_dir() . '/delivery-authorization.json';
    $handle = @fopen($path . '.lock', 'c+');
    if ($handle === false) { return $fail; }
    try {
        if (!flock($handle, LOCK_EX)) { return $fail; }
        $exists = is_file($path);
        $raw = $exists ? @file_get_contents($path) : '';
        $state = !$exists ? ['nonces' => [], 'runs' => []] : json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($state) || !is_array($state['nonces'] ?? null) || !is_array($state['runs'] ?? null)
            || isset($state['nonces'][$nonce]) || isset($state['runs'][$run])
            || count($state['runs']) >= 1000) { return $fail; }
        $state['nonces'][$nonce] = time(); $state['runs'][$run] = time();
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $out = @fopen($temp, 'x');
        if ($out === false) { return $fail; }
        $saved = fwrite($out, $json) === strlen($json) && fflush($out) && fsync($out);
        fclose($out);
        if (!$saved || !@rename($temp, $path)) { @unlink($temp); return $fail; }
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    return ['ok' => true, 'run_id' => $run, 'payload_hash' => $payloadHash,
        'notification_recipient' => $config['notification_recipient']];
}

function bh_delivery_receipt(array $receipt): bool
{
    $dir = bh_security_root_dir() . '/delivery-tests';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { return false; }
    $path = $dir . '/' . $receipt['qa_run_id'] . '.json';
    $json = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) { return false; }
    $handle = @fopen($path, 'c+');
    if ($handle === false) { return false; }
    try {
        if (!flock($handle, LOCK_EX) || !ftruncate($handle, 0) || !rewind($handle)) { return false; }
        return fwrite($handle, $json) === strlen($json) && fflush($handle) && fsync($handle);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function bh_delivery_append(array $lead): bool
{
    $path = bh_security_root_dir() . '/delivery-tests.csv';
    $handle = @fopen($path, 'c+');
    if ($handle === false) { return false; }
    try {
        if (!flock($handle, LOCK_EX) || fseek($handle, 0, SEEK_END) !== 0) { return false; }
        $keys = ['submitted_at_iso', 'lead_id', 'qa_run_id', 'name', 'email', 'phone', 'marketing_consent'];
        if (ftell($handle) === 0 && fputcsv($handle, $keys) === false) { return false; }
        $values = array_map(static fn ($key) => (string)$lead[$key], $keys);
        return fputcsv($handle, $values) !== false && fflush($handle) && fsync($handle);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function bh_delivery_finish(array $lead, array $authorization, array $turnstile): void
{
    $run = $authorization['run_id'];
    $lead['qa_mode'] = 'developer'; $lead['qa_run_id'] = $run;
    $lead['marketing_consent'] = 'no'; $lead['mailerlite_status'] = 'skipped:internal_delivery_test';
    $receipt = ['qa_run_id' => $run, 'lead_id' => $lead['lead_id'], 'timestamp' => gmdate('c'),
        'payload_hash' => $authorization['payload_hash'], 'status' => 'provider_verified',
        'turnstile_hostname' => $turnstile['hostname'], 'turnstile_action' => $turnstile['action'],
        'crm_ok' => false, 'mail_accepted' => false, 'owner_receipt_proven' => false,
        'business_kpi_written' => false, 'nurture_sent' => false];
    if (!bh_delivery_receipt($receipt) || !bh_delivery_append($lead)) {
        respond(false, 'QA storage failed. Do not retry; reconcile the private receipt.', 503);
    }
    $receipt['status'] = 'crm_attempt_pending';
    if (!bh_delivery_receipt($receipt)) { respond(false, 'QA receipt unavailable. Do not retry.', 503); }
    $crm = crm_sync_lead($lead);
    $receipt['crm_ok'] = ($crm['ok'] ?? false) === true;
    $receipt['crm_status'] = (string)($crm['status'] ?? 'failed');
    $receipt['status'] = $receipt['crm_ok'] ? 'mail_attempt_pending' : 'crm_failed';
    if (!bh_delivery_receipt($receipt) || !$receipt['crm_ok']) {
        respond(false, 'QA CRM delivery not confirmed. Do not retry; reconcile the private receipt.', 503);
    }
    $subject = 'FFC QA TEST — ' . $run . ' — DO NOT CONTACT';
    $text = "Internal Blue Haven delivery test. Exclude all KPIs and follow-up.\nQA Run: {$run}\nLead ID: {$lead['lead_id']}\nProvider: verified\nConsent: 0\nNot proof of Stephen inbox delivery.\n";
    $receipt['mail_accepted'] = send_owner_email($subject, '<pre>' . h($text) . '</pre>', $text,
        $lead['email'], $lead['name'], $authorization['notification_recipient']);
    $receipt['status'] = $receipt['mail_accepted'] ? 'completed' : 'mail_failed';
    if (!bh_delivery_receipt($receipt) || !$receipt['mail_accepted']) {
        respond(false, 'QA internal mail not confirmed. Do not retry; reconcile the private receipt.', 503);
    }
    // mail() acceptance is transport proof only, never inbox receipt proof.
    header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
    echo json_encode(['success' => true, 'message' => 'Internal QA delivery accepted. Inbox receipt requires separate readback. No owner notification or follow-up sent.',
        'lead_id' => $lead['lead_id'], 'qa_authorized' => true, 'qa_delivery_verified' => true,
        'qa_run_id' => $run, 'crm' => crm_response_payload($crm)]);
    exit;
}
