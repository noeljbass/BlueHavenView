<?php
declare(strict_types=1);

const MAILERLITE_API_BASE = 'https://connect.mailerlite.com/api';
const MAILERLITE_CONFIG_FILE = __DIR__ . '/data/mailerlite-config.php';

function mailerlite_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    if (!is_file(MAILERLITE_CONFIG_FILE)) {
        $config = [];
        return $config;
    }

    $loaded = require MAILERLITE_CONFIG_FILE;
    $config = is_array($loaded) ? $loaded : [];

    return $config;
}

function mailerlite_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && trim((string)$value) !== '') {
        return trim((string)$value);
    }

    $config = mailerlite_config();
    if (isset($config[$key]) && trim((string)$config[$key]) !== '') {
        return trim((string)$config[$key]);
    }

    return $default;
}

function mailerlite_bool_env(string $key, bool $default = false): bool
{
    $value = strtolower(mailerlite_env($key));
    if ($value === '') {
        return $default;
    }

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function mailerlite_csv_value(array $lead, string $key): string
{
    return trim((string)($lead[$key] ?? ''));
}

function mailerlite_group_ids(array $lead): array
{
    $leadType = strtolower(mailerlite_csv_value($lead, 'lead_type'));
    $specificGroup = str_contains($leadType, 'guide')
        ? mailerlite_env('MAILERLITE_GUIDE_GROUP_ID')
        : mailerlite_env('MAILERLITE_CONTACT_GROUP_ID');
    $fallbackGroup = mailerlite_env('MAILERLITE_GROUP_ID');
    $groupIds = array_filter([$specificGroup, $fallbackGroup], static fn (string $groupId): bool => $groupId !== '');

    return array_values(array_unique($groupIds));
}

function mailerlite_payload(array $lead): array
{
    $firstName = mailerlite_csv_value($lead, 'first_name');
    $lastName = mailerlite_csv_value($lead, 'last_name');
    $fullName = mailerlite_csv_value($lead, 'name');
    $phone = mailerlite_csv_value($lead, 'phone');
    $fields = [];

    if ($firstName !== '') {
        $fields['name'] = $firstName;
    } elseif ($fullName !== '') {
        $fields['name'] = $fullName;
    }

    if ($lastName !== '') {
        $fields['last_name'] = $lastName;
    }

    if ($phone !== '') {
        $fields['phone'] = $phone;
    }

    $payload = [
        'email' => mailerlite_csv_value($lead, 'email'),
    ];

    if ($fields !== []) {
        $payload['fields'] = $fields;
    }

    $groupIds = mailerlite_group_ids($lead);
    if ($groupIds !== []) {
        $payload['groups'] = $groupIds;
    }

    return $payload;
}

function mailerlite_request(string $method, string $path, array $payload, string $apiKey): array
{
    $url = rtrim(MAILERLITE_API_BASE, '/') . '/' . ltrim($path, '/');
    $jsonPayload = json_encode($payload);

    if ($jsonPayload === false) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'MailerLite payload could not be encoded.'];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'Could not initialize MailerLite request.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $error === '' && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $jsonPayload,
            'ignore_errors' => true,
            'timeout' => 12,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    $status = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int)$matches[1];
            break;
        }
    }

    return [
        'ok' => $body !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'error' => $body === false ? 'MailerLite request failed.' : '',
    ];
}

function mailerlite_result_message(array $result): string
{
    $error = trim((string)($result['error'] ?? ''));
    if (str_starts_with($error, 'skipped:')) {
        return $error;
    }

    if (($result['ok'] ?? false) === true) {
        return 'synced';
    }

    $status = (int)($result['status'] ?? 0);
    $body = trim((string)($result['body'] ?? ''));
    $message = 'failed';

    if ($status > 0) {
        $message .= ': HTTP ' . $status;
    }

    if ($error !== '') {
        $message .= ' ' . $error;
    } elseif ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['message'])) {
            $message .= ' ' . mb_substr((string)$decoded['message'], 0, 160);
        }
    }

    return $message;
}

function mailerlite_sync_lead(array $lead): array
{
    $email = mailerlite_csv_value($lead, 'email');
    if ($email === '') {
        return ['ok' => true, 'status' => 0, 'body' => '', 'error' => 'skipped: no email'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'status' => 0, 'body' => '', 'error' => 'skipped: invalid email'];
    }

    $consent = strtolower(mailerlite_csv_value($lead, 'marketing_consent'));
    if (!in_array($consent, ['1', 'yes', 'true', 'on'], true)) {
        return ['ok' => true, 'status' => 0, 'body' => '', 'error' => 'skipped: no marketing consent'];
    }

    $apiKey = mailerlite_env('MAILERLITE_API_KEY');
    if ($apiKey === '') {
        return ['ok' => true, 'status' => 0, 'body' => '', 'error' => 'skipped: missing MAILERLITE_API_KEY'];
    }

    return mailerlite_request('POST', '/subscribers', mailerlite_payload($lead), $apiKey);
}
