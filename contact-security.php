<?php
declare(strict_types=1);

function bh_security_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    static $fileConfig = null;
    if ($fileConfig === null) {
        $path = __DIR__ . '/data/contact-security-config.php';
        $fileConfig = is_file($path) ? require $path : [];
        if (!is_array($fileConfig)) { $fileConfig = []; }
    }
    return isset($fileConfig[$key]) && is_scalar($fileConfig[$key]) ? trim((string)$fileConfig[$key]) : $default;
}

function bh_security_root_dir(): string
{
    $dir = bh_security_env('BLUEHAVEN_SECURITY_DIR');
    if ($dir !== '') {
        return rtrim($dir, DIRECTORY_SEPARATOR);
    }

    return __DIR__ . '/data/security';
}

function bh_security_state_path(): string
{
    return bh_security_root_dir() . DIRECTORY_SEPARATOR . 'security-state.json';
}

function bh_security_review_path(): string
{
    return bh_security_root_dir() . DIRECTORY_SEPARATOR . 'security-review.jsonl';
}

function bh_security_ensure_root(): bool
{
    $dir = bh_security_root_dir();
    if (is_dir($dir)) {
        return true;
    }

    return mkdir($dir, 0700, true);
}

function bh_security_default_state(): array
{
    return [
        'turnstile_pending' => [],
        'turnstile_used' => [],
        'qa_nonces' => [],
        'email_attempts' => [],
        'ip_attempts' => [],
        'message_fingerprints' => [],
    ];
}

function bh_security_load_state(): array
{
    $path = bh_security_state_path();
    if (!is_file($path)) {
        return bh_security_default_state();
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return bh_security_default_state();
    }

    return array_merge(bh_security_default_state(), $decoded);
}

function bh_security_write_state(array $state): bool
{
    if (!bh_security_ensure_root()) {
        return false;
    }

    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }

    $path = bh_security_state_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }

        ftruncate($handle, 0);
        rewind($handle);
        if (fwrite($handle, $json) === false) {
            return false;
        }
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bh_security_mutate_state(callable $mutator): array
{
    if (!bh_security_ensure_root()) {
        return ['ok' => false, 'status' => 'state_unavailable'];
    }

    $path = bh_security_state_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        return ['ok' => false, 'status' => 'state_unavailable'];
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return ['ok' => false, 'status' => 'state_unavailable'];
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $state = json_decode(is_string($raw) ? $raw : '', true);
        if ($raw !== '' && !is_array($state)) {
            return ['ok' => false, 'status' => 'state_corrupt'];
        }
        if (!is_array($state)) {
            $state = bh_security_default_state();
        }

        $state = bh_security_prune_state($state);
        $result = $mutator($state);
        if (!is_array($result) || ($result['ok'] ?? false) !== true) {
            return is_array($result) ? $result : ['ok' => false, 'status' => 'state_error'];
        }

        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'status' => 'state_encode_failed'];
        }

        ftruncate($handle, 0);
        rewind($handle);
        if (fwrite($handle, $json) === false) {
            return ['ok' => false, 'status' => 'state_write_failed'];
        }
        fflush($handle);

        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bh_security_prune_state(array $state): array
{
    $now = time();
    $turnstileTtl = 24 * 3600;
    $qaTtl = 10 * 60;
    $attemptWindow = 24 * 3600;
    $burstWindow = 10 * 60;

    $state['turnstile_pending'] = array_filter(
        (array)($state['turnstile_pending'] ?? []),
        static fn ($expiresAt) => is_int($expiresAt) ? $expiresAt > $now : ((int)$expiresAt) > $now
    );
    $state['turnstile_used'] = array_filter(
        (array)($state['turnstile_used'] ?? []),
        static fn ($usedAt) => is_int($usedAt) ? ($usedAt + $turnstileTtl) > $now : (((int)$usedAt) + $turnstileTtl) > $now
    );
    $state['qa_nonces'] = array_filter(
        (array)($state['qa_nonces'] ?? []),
        static fn ($expiresAt) => is_int($expiresAt) ? $expiresAt > $now : ((int)$expiresAt) > $now
    );

    foreach (['email_attempts' => $attemptWindow, 'ip_attempts' => $burstWindow] as $bucket => $window) {
        $state[$bucket] = is_array($state[$bucket] ?? null) ? $state[$bucket] : [];
        foreach ($state[$bucket] as $key => $timestamps) {
            $timestamps = array_values(array_filter((array)$timestamps, static function ($timestamp) use ($now, $window): bool {
                $ts = is_int($timestamp) ? $timestamp : (int)$timestamp;
                return $ts > 0 && ($ts + $window) > $now;
            }));
            if ($timestamps === []) {
                unset($state[$bucket][$key]);
            } else {
                $state[$bucket][$key] = $timestamps;
            }
        }
    }

    $state['message_fingerprints'] = is_array($state['message_fingerprints'] ?? null) ? $state['message_fingerprints'] : [];
    foreach ($state['message_fingerprints'] as $key => $records) {
        $records = array_values(array_filter((array)$records, static function ($record) use ($now, $attemptWindow): bool {
            $ts = (int)($record['timestamp'] ?? 0);
            return $ts > 0 && ($ts + $attemptWindow) > $now;
        }));
        if ($records === []) {
            unset($state['message_fingerprints'][$key]);
        } else {
            $state['message_fingerprints'][$key] = $records;
        }
    }

    return $state;
}

function bh_security_record_review(array $record): bool
{
    if (!bh_security_ensure_root()) {
        return false;
    }

    $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return false;
    }

    return file_put_contents(bh_security_review_path(), $line . "\n", FILE_APPEND | LOCK_EX) !== false;
}

function bh_security_hash(string $value): string
{
    return hash('sha256', $value);
}

function bh_normalize_email(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '' || !str_contains($email, '@')) {
        return $email;
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($domain !== 'gmail.com') {
        return $email;
    }

    $local = preg_replace('/\+.*$/', '', $local) ?? $local;
    $local = str_replace('.', '', $local);
    return $local . '@' . $domain;
}

function bh_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        $digits = substr($digits, 1);
    }

    return $digits;
}

function bh_is_fictional_nanp_phone(string $phone): bool
{
    $digits = bh_normalize_phone($phone);
    return (bool)preg_match('/^55501\d{2}$/', substr($digits, -7));
}

function bh_normalize_message(string $message): string
{
    $message = mb_strtolower(trim($message));
    $message = preg_replace('/\s+/u', ' ', $message) ?? $message;
    return $message;
}

function bh_normalize_name(string $firstName, string $lastName): string
{
    $name = trim(mb_strtolower($firstName . ' ' . $lastName));
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return $name;
}

function bh_turnstile_config(): array
{
    return [
        'sitekey' => bh_security_env('TURNSTILE_SITEKEY'),
        'secret' => bh_security_env('TURNSTILE_SECRET_KEY'),
        'hostname' => bh_security_env('TURNSTILE_ALLOWED_HOSTNAME', 'bluehavenview.com,www.bluehavenview.com'),
        'action' => bh_security_env('TURNSTILE_ALLOWED_ACTION', 'contact_form'),
        'timeout' => max(2, (int)bh_security_env('TURNSTILE_TIMEOUT_SECONDS', '5')),
    ];
}

function bh_turnstile_siteverify(array $form, array $server): array
{
    $config = bh_turnstile_config();
    if ($config['secret'] === '' || $config['hostname'] === '' || $config['action'] === '') {
        return ['ok' => false, 'status' => 'missing_turnstile_config'];
    }

    $token = $form['turnstile_token'] ?? $form['cf-turnstile-response'] ?? '';
    if (!is_string($token) || strlen($token) > 2048) {
        return ['ok' => false, 'status' => 'turnstile_invalid_token'];
    }
    $token = trim($token);
    if ($token === '') {
        return ['ok' => false, 'status' => 'missing_turnstile_token'];
    }

    $tokenHash = bh_security_hash($token);
    $reservation = bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
        $pending = (int)($state['turnstile_pending'][$tokenHash] ?? 0);
        $used = (int)($state['turnstile_used'][$tokenHash] ?? 0);
        if ($pending > time() || $used > 0) {
            return ['ok' => false, 'status' => 'turnstile_replay'];
        }

        $state['turnstile_pending'][$tokenHash] = time() + 15 * 60;
        return ['ok' => true];
    });

    if (($reservation['ok'] ?? false) !== true) {
        return $reservation;
    }

    // CLI-only test transport: never configurable by public HTTP or SFTP config.
    $mock = PHP_SAPI === 'cli' ? (string)getenv('BLUEHAVEN_TURNSTILE_SITEVERIFY_MOCK') : '';
    if ($mock === 'timeout') {
        bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
            unset($state['turnstile_pending'][$tokenHash]);
            return ['ok' => true];
        });
        return ['ok' => false, 'status' => 'turnstile_timeout'];
    }

    if ($mock !== '') {
        $raw = $mock;
    } else {
        $payload = http_build_query([
            'secret' => $config['secret'],
            'response' => $token,
            'remoteip' => trim((string)($server['REMOTE_ADDR'] ?? '')),
        ], '', '&');

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => $config['timeout'],
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if ($raw === false) {
            bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
                unset($state['turnstile_pending'][$tokenHash]);
                return ['ok' => true];
            });
            return ['ok' => false, 'status' => 'turnstile_timeout'];
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || (($decoded['success'] ?? false) !== true)
        || !is_string($decoded['hostname'] ?? null) || !is_string($decoded['action'] ?? null)) {
        bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
            unset($state['turnstile_pending'][$tokenHash]);
            return ['ok' => true];
        });
        return ['ok' => false, 'status' => 'turnstile_invalid_token'];
    }

    $hostname = strtolower(trim((string)($decoded['hostname'] ?? '')));
    $action = strtolower(trim((string)($decoded['action'] ?? '')));
    if (!in_array($hostname, array_map('trim', explode(',', strtolower($config['hostname']))), true)) {
        bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
            unset($state['turnstile_pending'][$tokenHash]);
            return ['ok' => true];
        });
        return ['ok' => false, 'status' => 'turnstile_wrong_hostname'];
    }

    if ($action !== strtolower($config['action'])) {
        bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
            unset($state['turnstile_pending'][$tokenHash]);
            return ['ok' => true];
        });
        return ['ok' => false, 'status' => 'turnstile_wrong_action'];
    }

    $commit = bh_security_mutate_state(static function (array &$state) use ($tokenHash): array {
        unset($state['turnstile_pending'][$tokenHash]);
        $state['turnstile_used'][$tokenHash] = time();
        return ['ok' => true];
    });

    if (($commit['ok'] ?? false) !== true) {
        return ['ok' => false, 'status' => 'turnstile_state_failed'];
    }

    return [
        'ok' => true,
        'status' => 'verified',
        'hostname' => $hostname,
        'action' => $action,
        'challenge' => 'turnstile',
    ];
}

function bh_qa_canonical_payload(array $form): string
{
    // Sign every posted scalar byte; only the envelope itself is excluded.
    foreach (['qa_timestamp', 'qa_nonce', 'qa_signature', 'qa_payload_hash'] as $key) { unset($form[$key]); }
    ksort($form, SORT_STRING);
    $pairs = [];
    foreach ($form as $key => $value) {
        if (!is_string($value)) { throw new InvalidArgumentException('Non-scalar QA field'); }
        $pairs[] = bin2hex((string)$key) . '=' . bin2hex($value);
    }
    return implode("\n", $pairs);
}

function bh_verify_signed_qa(array $form, array $server): array
{
    $secret = bh_security_env('BLUEHAVEN_QA_HMAC_SECRET');
    if ($secret === '') {
        return ['ok' => false, 'status' => 'qa_missing_secret'];
    }

    $timestamp = trim((string)($server['HTTP_X_BLUEHAVEN_QA_TIMESTAMP'] ?? ($form['qa_timestamp'] ?? '')));
    $nonce = trim((string)($server['HTTP_X_BLUEHAVEN_QA_NONCE'] ?? ($form['qa_nonce'] ?? '')));
    $signature = trim((string)($server['HTTP_X_BLUEHAVEN_QA_SIGNATURE'] ?? ($form['qa_signature'] ?? '')));
    $payloadHash = trim((string)($server['HTTP_X_BLUEHAVEN_QA_PAYLOAD_HASH'] ?? ($form['qa_payload_hash'] ?? '')));

    if ($timestamp === '' || $nonce === '' || $signature === '' || $payloadHash === '') {
        return ['ok' => false, 'status' => 'qa_missing_signature'];
    }

    if (!preg_match('/^\d{10}$/', $timestamp)) {
        return ['ok' => false, 'status' => 'qa_bad_timestamp'];
    }

    $now = time();
    if (abs($now - (int)$timestamp) > 300) {
        return ['ok' => false, 'status' => 'qa_expired'];
    }

    $payload = bh_qa_canonical_payload($form);
    $actualPayloadHash = bh_security_hash($payload);
    if (!hash_equals($actualPayloadHash, $payloadHash)) {
        return ['ok' => false, 'status' => 'qa_payload_mismatch'];
    }

    $canonical = implode("\n", [
        'v1',
        'method=' . strtoupper((string)($server['REQUEST_METHOD'] ?? 'POST')),
        'path=' . (string)parse_url((string)($server['REQUEST_URI'] ?? '/contact.php'), PHP_URL_PATH),
        'host=' . strtolower(trim((string)($server['HTTP_HOST'] ?? ''))),
        'timestamp=' . $timestamp,
        'nonce=' . $nonce,
        'payload=' . $actualPayloadHash,
    ]);
    $expected = hash_hmac('sha256', $canonical, $secret);
    if (!hash_equals($expected, $signature)) {
        return ['ok' => false, 'status' => 'qa_bad_signature'];
    }

    $nonceReservation = bh_security_mutate_state(static function (array &$state) use ($nonce): array {
        $expiresAt = (int)($state['qa_nonces'][$nonce] ?? 0);
        if ($expiresAt > time()) {
            return ['ok' => false, 'status' => 'qa_replay'];
        }

        $state['qa_nonces'][$nonce] = time() + 10 * 60;
        return ['ok' => true];
    });

    if (($nonceReservation['ok'] ?? false) !== true) {
        return $nonceReservation;
    }

    return [
        'ok' => true,
        'status' => 'authorized',
        'timestamp' => (int)$timestamp,
        'nonce' => $nonce,
        'payload_hash' => $actualPayloadHash,
        'mode' => 'qa',
    ];
}

function bh_security_rate_decision(array $lead, string $ipAddress): array
{
    $emailKey = bh_normalize_email((string)($lead['email'] ?? ''));
    $ipKey = trim($ipAddress);
    $messageKey = bh_normalize_message((string)($lead['message'] ?? ''));
    $nameKey = bh_normalize_name((string)($lead['first_name'] ?? ''), (string)($lead['last_name'] ?? ''));
    $phoneKey = bh_normalize_phone((string)($lead['phone'] ?? ''));
    $isFictionalPhone = bh_is_fictional_nanp_phone((string)($lead['phone'] ?? ''));
    $now = time();

    $update = bh_security_mutate_state(static function (array &$state) use ($emailKey, $ipKey, $messageKey, $nameKey, $phoneKey, $isFictionalPhone, $now): array {
        $emailHistory = (array)($state['email_attempts'][$emailKey] ?? []);
        $emailHistory = array_values(array_filter($emailHistory, static fn ($timestamp) => ((int)$timestamp + 24 * 3600) > $now));
        $ipHistory = (array)($state['ip_attempts'][$ipKey] ?? []);
        $ipHistory = array_values(array_filter($ipHistory, static fn ($timestamp) => ((int)$timestamp + 10 * 60) > $now));
        $messageHistory = (array)($state['message_fingerprints'][bh_security_hash($messageKey)] ?? []);
        $messageHistory = array_values(array_filter($messageHistory, static fn ($record) => ((int)($record['timestamp'] ?? 0) + 24 * 3600) > $now));

        $equivalentEmailAttempts = count($emailHistory);
        $burstAttempts = count($ipHistory);
        $signalFlags = [];

        foreach ($messageHistory as $record) {
            $changedName = (string)($record['name_key'] ?? '') !== $nameKey;
            $changedEmail = (string)($record['email_key'] ?? '') !== $emailKey;
            $changedPhone = (string)($record['phone_key'] ?? '') !== $phoneKey;

            if ($messageKey !== '' && ($changedName || $changedEmail || $changedPhone)) {
                $signalFlags[] = 'repeated_message_with_identity_change';
                break;
            }
        }

        // Reused generic text or changed identity alone is not evidence of spam.
        $compoundReasons = [];
        if ($isFictionalPhone && $messageKey !== '' && $messageHistory !== [] && $nameKey !== '') {
            $compoundReasons[] = 'fictional_phone_and_repeat';
        }

        $decision = 'accept';
        if ($emailKey !== '' && $equivalentEmailAttempts >= 2) {
            $decision = 'quarantine';
            $compoundReasons[] = 'email_rate_limit';
        }

        if ($burstAttempts >= 10) {
            $decision = 'quarantine';
            $compoundReasons[] = 'ip_burst_limit';
        }

        if ($compoundReasons !== []) {
            $decision = 'quarantine';
        }

        if ($emailKey !== '') {
            $state['email_attempts'][$emailKey] = array_merge($emailHistory, [$now]);
        }
        if ($ipKey !== '') {
            $state['ip_attempts'][$ipKey] = array_merge($ipHistory, [$now]);
        }
        $state['message_fingerprints'][bh_security_hash($messageKey)] = array_merge($messageHistory, [[
            'timestamp' => $now,
            'name_key' => $nameKey,
            'email_key' => $emailKey,
            'phone_key' => $phoneKey,
            'fictional_phone' => $isFictionalPhone ? 1 : 0,
        ]]);

        return [
            'ok' => true,
            'decision' => $decision,
            'email_attempts' => count($state['email_attempts'][$emailKey] ?? []),
            'ip_attempts' => $ipKey !== '' ? count($state['ip_attempts'][$ipKey] ?? []) : 0,
            'compound_reasons' => array_values(array_unique($compoundReasons)),
            'signal_flags' => $signalFlags,
            'normalized_email' => $emailKey,
            'normalized_phone' => $phoneKey,
            'normalized_message_hash' => bh_security_hash($messageKey),
        ];
    });

    return $update;
}
