<?php
declare(strict_types=1);
require_once __DIR__ . '/lead-attribution.php';
require_once __DIR__ . '/consent-ledger.php';

const CONTACT_RECIPIENT = 'info@bluehavenview.com';
const SITE_URL = 'https://bluehavenview.com';
const SITE_NAME = 'Blue Haven Windows';
const GUIDE_FILE = __DIR__ . '/WindowsBuyersGuide.pdf';
const GUIDE_FILE_NAME = 'Blue-Haven-Windows-Buyers-Guide.pdf';
const LEADS_CSV = __DIR__ . '/data/lead-submissions-v2.csv';
const CRM_CONFIG_FILE = __DIR__ . '/.bluehaven-crm-config.php';
const CRM_FAILURE_LOG = __DIR__ . '/data/crm-sync-failures.log';

require_once __DIR__ . '/mailerlite.php';
require_once __DIR__ . '/contact-security.php';
require_once __DIR__ . '/contact-delivery-test.php';

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}


function google_tag(): string
{
    return <<<'HTML'
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-WTEM34PS2J"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-WTEM34PS2J');
</script>
HTML;
}

function chat_widget(): string
{
    return '<script src="https://bastiontech.org/widgets/chat-widget.js" data-client="blue-haven-windows"></script>';
}

function respond(bool $success, string $message, int $statusCode = 200, ?string $leadId = null, bool $isQa = false, ?array $crm = null, string $measurementKind = ''): void
{
    http_response_code($statusCode);

    if (wants_json()) {
        $payload = [
            'success' => $success,
            'message' => $message,
            'lead_id' => $success ? $leadId : null,
            'measurement_kind' => $success && !$isQa ? $measurementKind : '',
            'attribution_persisted' => $GLOBALS['attributionStored'] ?? null,
        ];

        if ($isQa) {
            $payload['crm'] = $crm;
            $payload['qa_authorized'] = true;
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    $heading = $success ? 'Thank you!' : 'Something went wrong';
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head>" . google_tag() . "<meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><meta name=\"theme-color\" content=\"#facc15\"><title>{$safeHeading} | " . SITE_NAME . "</title></head><body style=\"font-family:Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 20px;line-height:1.6\"><h1>{$safeHeading}</h1><p>{$safeMessage}</p><p><a href=\"/\">Return to " . SITE_NAME . "</a></p>" . chat_widget() . "</body></html>";
    exit;
}

function clean(string $key, int $maxLength = 1000): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $maxLength);
}

function valid_qa_run_id(string $value): bool
{
    return preg_match('/^BHQA-\d{8}-[A-Z0-9]{6}$/', $value) === 1;
}

function request_client_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
}

function security_failure_response(string $message, string $status, int $httpStatus = 403): void
{
    if (!bh_security_record_review([
        'timestamp' => gmdate('c'),
        'mode' => 'security',
        'status' => 'rejected',
        'reason' => $status,
        'message' => $message,
    ])) {
        respond(false, 'Security review storage is unavailable. Please try again later.', 503);
    }

    respond(false, $message, $httpStatus);
}

function security_review_record(array $lead, array $decision, string $mode, array $extra = []): void
{
    $record = array_merge([
        'timestamp' => gmdate('c'),
        'mode' => $mode,
        'status' => $decision['decision'] ?? ($decision['status'] ?? 'unknown'),
        'lead_id' => $lead['lead_id'] ?? null,
        'email_hash' => bh_security_hash(bh_normalize_email((string)($lead['email'] ?? ''))),
        'ip_hash' => bh_security_hash((string)($lead['ip_address'] ?? '')),
        'message_hash' => $decision['normalized_message_hash'] ?? null,
    ], $extra);

    if (!bh_security_record_review($record)) {
        respond(false, 'Security review storage is unavailable. Please try again later.', 503);
    }
}

function security_submission_message(array $decision, bool $qaAuthorized): string
{
    if ($qaAuthorized) {
        return 'QA submission recorded locally. No owner notifications or marketing actions were sent.';
    }

    if (($decision['decision'] ?? '') === 'quarantine') {
        return 'Your submission needs manual review. No confirmation or thank-you redirect was sent.';
    }

    return '';
}

function security_turnstile_message(string $status): array
{
    return match ($status) {
        'missing_turnstile_config' => [503, 'Security challenge is not configured for this site. Please try again later.'],
        'missing_turnstile_token' => [403, 'Please complete the security check and submit the form again.'],
        'turnstile_timeout' => [503, 'The security check timed out. Please refresh the page and try again.'],
        'turnstile_invalid_token' => [403, 'The security check failed. Please try again.'],
        'turnstile_replay' => [403, 'The security check was already used. Please refresh the page and try again.'],
        'turnstile_wrong_hostname' => [403, 'The security check came from the wrong host. Please refresh the page and try again.'],
        'turnstile_wrong_action' => [403, 'The security check action did not match this form. Please refresh the page and try again.'],
        'turnstile_state_failed' => [503, 'Security review storage is unavailable. Please try again later.'],
        default => [403, 'The security check failed. Please try again.'],
    };
}

function clean_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return SITE_URL;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return SITE_URL;
    }

    return mb_substr($url, 0, 500);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function is_guide_request(string $formName, string $projectType, string $message): bool
{
    $haystack = strtolower($formName . ' ' . $projectType . ' ' . $message);
    return str_contains($haystack, 'guide') || str_contains($haystack, 'buyer');
}

function marketing_consent_granted(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'yes', 'true', 'on'], true);
}

function crm_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    if (!is_file(CRM_CONFIG_FILE)) {
        $config = [];
        return $config;
    }

    $loaded = require CRM_CONFIG_FILE;
    $config = is_array($loaded) ? $loaded : [];

    return $config;
}

function crm_config_value(string $key): string
{
    $config = crm_config();
    if (!isset($config[$key])) {
        return '';
    }

    return trim((string)$config[$key]);
}

function crm_endpoint_is_valid(string $endpoint): bool
{
    return str_starts_with($endpoint, 'https://') && filter_var($endpoint, FILTER_VALIDATE_URL) !== false;
}

function crm_payload(array $lead): array
{
    return [
        'token' => crm_config_value('token'),
        'lead_id' => $lead['lead_id'],
        'timestamp' => $lead['submitted_at_iso'],
        'first_name' => $lead['first_name'],
        'last_name' => $lead['last_name'],
        'name' => $lead['name'],
        'email' => $lead['email'],
        'phone' => $lead['phone'],
        'zipcode' => $lead['zipcode'],
        'service' => $lead['project_type'],
        'message' => $lead['message'],
        'page_url' => $lead['source_page'],
        'page_title' => $lead['page_title'],
        'landing_page' => $lead['source_page'],
        'oppref' => $lead['oppref'] ?? '',
        'gclid' => $lead['gclid'],
        'gbraid' => $lead['gbraid'],
        'wbraid' => $lead['wbraid'],
        'utm_source' => $lead['utm_source'],
        'utm_medium' => $lead['utm_medium'],
        'utm_campaign' => $lead['utm_campaign'],
        'utm_term' => $lead['utm_term'],
        'utm_content' => $lead['utm_content'],
        'qa_mode' => $lead['qa_mode'],
        'qa_run_id' => $lead['qa_run_id'],
        'first_touch_type' => 'Website Form',
        'google_consent' => $lead['google_consent'] ?? bh_choice_unknown(),
        'raw_campaign_evidence' => $lead['raw_campaign_evidence'] ?? null,
    ];
}

function crm_response_payload(array $crmResult): array
{
    return [
        'ok' => ($crmResult['ok'] ?? false) === true,
        'status' => (string)($crmResult['status'] ?? 'failed'),
    ];
}

function log_crm_sync_failure(string $leadId, string $status): void
{
    $dir = dirname(CRM_FAILURE_LOG);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $line = json_encode([
        'timestamp' => gmdate('c'),
        'lead_id' => $leadId,
        'status' => $status,
    ]);

    if ($line === false) {
        return;
    }

    $line .= "\n";
    file_put_contents(CRM_FAILURE_LOG, $line, FILE_APPEND | LOCK_EX);
}

function crm_decode_success(string $body): bool
{
    $decoded = json_decode($body, true);
    return is_array($decoded) && (($decoded['ok'] ?? false) === true);
}

function crm_http_status_label(int $statusCode): string
{
    return $statusCode > 0 ? 'http_' . $statusCode : 'http_error';
}

function crm_request(string $endpoint, array $payload): array
{
    $jsonPayload = json_encode($payload);
    if ($jsonPayload === false) {
        return ['ok' => false, 'status' => 'payload_encode_failed'];
    }

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['ok' => false, 'status' => 'transport_init_failed'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return ['ok' => false, 'status' => 'transport_error'];
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'status' => crm_http_status_label($statusCode)];
        }

        if (!crm_decode_success($body)) {
            return ['ok' => false, 'status' => 'invalid_response'];
        }

        return ['ok' => true, 'status' => 'synced'];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $jsonPayload,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 3,
            'timeout' => 6,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = file_get_contents($endpoint, false, $context);
    $statusCode = 0;

    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $statusCode = (int)$matches[1];
            break;
        }
    }

    if ($body === false) {
        return ['ok' => false, 'status' => 'transport_error'];
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'status' => crm_http_status_label($statusCode)];
    }

    if (!crm_decode_success($body)) {
        return ['ok' => false, 'status' => 'invalid_response'];
    }

    return ['ok' => true, 'status' => 'synced'];
}

function crm_sync_lead(array $lead): array
{
    $endpoint = crm_config_value('endpoint');
    $token = crm_config_value('token');

    if ($endpoint === '' || $token === '') {
        return ['ok' => false, 'status' => 'not_configured'];
    }

    if (!crm_endpoint_is_valid($endpoint)) {
        return ['ok' => false, 'status' => 'invalid_endpoint'];
    }

    return crm_request($endpoint, crm_payload($lead));
}

function append_lead(array $lead): void
{
    $dir = dirname(LEADS_CSV);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $isNewFile = !file_exists(LEADS_CSV);
    $handle = fopen(LEADS_CSV, 'ab');
    if (!$handle) {
        return;
    }

    if (flock($handle, LOCK_EX)) {
        if ($isNewFile) {
            fputcsv($handle, [
                'submitted_at',
                'lead_id',
                'lead_type',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'zipcode',
                'project_type',
                'message',
                'source_page',
                'page_title',
                'form_name',
                'ip_address',
                'user_agent',
                'marketing_consent',
                'mailerlite_status',
                'gclid',
                'gbraid',
                'wbraid',
                'fbclid',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
            ]);
        }

        fputcsv($handle, [
            $lead['submitted_at_iso'],
            $lead['lead_id'],
            $lead['lead_type'],
            $lead['first_name'],
            $lead['last_name'],
            $lead['name'],
            $lead['email'],
            $lead['phone'],
            $lead['zipcode'],
            $lead['project_type'],
            $lead['message'],
            $lead['source_page'],
            $lead['page_title'],
            $lead['form_name'],
            $lead['ip_address'],
            $lead['user_agent'],
            $lead['marketing_consent'] ?? '',
            $lead['mailerlite_status'] ?? '',
            $lead['gclid'] ?? '',
            $lead['gbraid'] ?? '',
            $lead['wbraid'] ?? '',
            $lead['fbclid'] ?? '',
            $lead['utm_source'] ?? '',
            $lead['utm_medium'] ?? '',
            $lead['utm_campaign'] ?? '',
            $lead['utm_term'] ?? '',
            $lead['utm_content'] ?? '',
        ]);
        fflush($handle);
        flock($handle, LOCK_UN);
    }

    fclose($handle);
}

function build_rows(array $rows): string
{
    $tableRows = '';
    foreach ($rows as $row) {
        $safeValue = $row['html'] ? $row['value'] : h($row['value']);
        $tableRows .= '<tr><th style="text-align:left;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;width:170px;color:#0f172a;vertical-align:top;">' . h($row['label']) . '</th><td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;color:#334155;">' . $safeValue . '</td></tr>';
    }

    return $tableRows;
}

function send_owner_email(string $subject, string $htmlBody, string $textBody, string $email, string $name, string $recipient = CONTACT_RECIPIENT): bool
{
    $boundary = 'b' . bin2hex(random_bytes(16));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . SITE_NAME . ' <no-reply@bluehavenview.com>',
    ];

    if ($email !== '') {
        $headers[] = 'Reply-To: ' . ($name !== '' ? mb_encode_mimeheader($name) . ' ' : '') . '<' . $email . '>';
    }

    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $textBody . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $htmlBody . "\r\n"
        . '--' . $boundary . "--\r\n";

    return mail($recipient, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
}

function send_guide_email(string $email, string $firstName): bool
{
    if ($email === '' || !is_file(GUIDE_FILE)) {
        return false;
    }

    $nameGreeting = $firstName !== '' ? $firstName : 'there';
    $subject = 'Your free Window Buyer\'s Guide from Blue Haven Windows';
    $textBody = "Hi {$nameGreeting},\n\nThank you for requesting the free Window Buyer's Guide from Blue Haven Windows. Your guide is attached to this email.\n\nIf you have questions about replacement windows, energy efficiency, warranties, installation, or pricing in Middle Tennessee, reply to this email or call Stephen at (615) 987-0593.\n\nBlue Haven Windows\nBetter Windows. Better Life.\nhttps://bluehavenview.com\n";
    $htmlBody = '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">'
        . '<div style="max-width:680px;margin:0 auto;padding:28px 16px;">'
        . '<div style="background:#0f2440;color:#fff;border-radius:18px;padding:28px;">'
        . '<p style="margin:0 0 12px;color:#fde68a;font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Free Window Buyer\'s Guide</p>'
        . '<h1 style="margin:0;font-size:28px;line-height:1.2;">Your guide is attached</h1>'
        . '<p style="color:#dbeafe;font-size:16px;line-height:1.6;margin:16px 0 0;">Hi ' . h($nameGreeting) . ', thank you for requesting the free Window Buyer\'s Guide from Blue Haven Windows.</p>'
        . '</div>'
        . '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:24px;margin-top:16px;">'
        . '<p style="font-size:16px;line-height:1.7;margin:0 0 16px;">The PDF is attached to this email so you can keep it handy while comparing products, warranties, installation details, and pricing questions.</p>'
        . '<p style="font-size:16px;line-height:1.7;margin:0;">Have questions? Reply to this email or call Stephen at <a href="tel:+16159870593" style="color:#1d4ed8;font-weight:700;">(615) 987-0593</a>.</p>'
        . '</div>'
        . '<p style="font-size:12px;color:#64748b;margin:18px 4px 0;">Blue Haven Windows · Better Windows. Better Life. · <a href="https://bluehavenview.com" style="color:#1d4ed8;">bluehavenview.com</a></p>'
        . '</div></body></html>';

    $mixedBoundary = 'm' . bin2hex(random_bytes(16));
    $altBoundary = 'a' . bin2hex(random_bytes(16));
    $attachment = chunk_split(base64_encode((string)file_get_contents(GUIDE_FILE)));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
        'From: ' . SITE_NAME . ' <info@bluehavenview.com>',
        'Reply-To: ' . SITE_NAME . ' <info@bluehavenview.com>',
    ];

    $body = '--' . $mixedBoundary . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"' . "\r\n\r\n"
        . '--' . $altBoundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
        . $textBody . "\r\n"
        . '--' . $altBoundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
        . $htmlBody . "\r\n"
        . '--' . $altBoundary . "--\r\n"
        . '--' . $mixedBoundary . "\r\n"
        . 'Content-Type: application/pdf; name="' . GUIDE_FILE_NAME . '"' . "\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . 'Content-Disposition: attachment; filename="' . GUIDE_FILE_NAME . '"' . "\r\n\r\n"
        . $attachment . "\r\n"
        . '--' . $mixedBoundary . "--\r\n";

    return mail($email, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Please submit the contact form from the website.', 405);
}

foreach ($_POST as $value) {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        respond(false, 'Invalid form fields. Please refresh and try again.', 422);
    }
}

if (clean('website') !== '') {
    security_failure_response('Please refresh the page and try again.', 'honeypot');
}

$firstName = clean('first_name', 80);
$lastName = clean('last_name', 80);
$email = clean('email', 180);
$phone = clean('phone', 80);
$zipcode = clean('zipcode', 20);
$message = clean('message', 3000);
$projectType = clean('project_type', 180);
$pageTitle = clean('page_title', 180);
$formName = clean('form_name', 180);
$sourcePage = clean_url(clean('source_page', 500) ?: ($_SERVER['HTTP_REFERER'] ?? SITE_URL));
$qaMode = strtolower(clean('qa_mode', 32));
$qaRunId = strtoupper(clean('qa_run_id', 40));
$utmSource = strtolower(clean('utm_source', 200));
$utmMedium = strtolower(clean('utm_medium', 200));
$marketingConsent = marketing_consent_granted(clean('marketing_consent', 20));
$name = trim($firstName . ' ' . $lastName);
$isGuideRequest = is_guide_request($formName, $projectType, $message);

if ($name === '' && $email === '' && $phone === '') {
    respond(false, 'Please include your name, email, or phone number so Stephen can follow up.', 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}

if ($zipcode !== '' && preg_match('/^\d{5}(?:-\d{4})?$/', $zipcode) !== 1) {
    respond(false, 'Please enter a valid ZIP code.', 422);
}

if ($isGuideRequest && $email === '') {
    respond(false, 'Please enter your email address so we can send the free guide.', 422);
}

$submittedAtDate = new DateTimeImmutable('now', new DateTimeZone('America/Chicago'));
$submittedAt = $submittedAtDate->format('F j, Y g:i A T');
$submittedAtIso = $submittedAtDate->format('Y-m-d\TH:i:s.vP');
$leadId = 'BH-' . $submittedAtDate->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
$subjectPage = $pageTitle !== '' ? $pageTitle : parse_url($sourcePage, PHP_URL_PATH);
$baseLeadType = $isGuideRequest ? 'Free Guide Request' : 'Contact Request';
$leadType = $baseLeadType;
$subject = 'Blue Haven Windows ' . strtolower($leadType) . ' from ' . $subjectPage;

$lead = [
    'submitted_at_iso' => $submittedAtIso,
    'lead_id' => $leadId,
    'lead_type' => $leadType,
    'first_name' => $firstName,
    'last_name' => $lastName,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'zipcode' => $zipcode,
    'project_type' => $projectType,
    'message' => $message,
    'source_page' => $sourcePage,
    'page_title' => $pageTitle,
    'form_name' => $formName,
    'ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    'marketing_consent' => $marketingConsent ? 'yes' : 'no',
    'oppref' => bh_valid_oppref($_POST['oppref'] ?? ''),
    'gclid' => clean('gclid', 200),
    'gbraid' => clean('gbraid', 200),
    'wbraid' => clean('wbraid', 200),
    'fbclid' => clean('fbclid', 200),
    'utm_source' => $utmSource,
    'utm_medium' => $utmMedium,
    'utm_campaign' => clean('utm_campaign', 200),
    'utm_term' => clean('utm_term', 200),
    'utm_content' => clean('utm_content', 200),
    'qa_mode' => $qaMode,
    'qa_run_id' => $qaRunId,
];
$deliveryRequested = bh_delivery_requested($_SERVER);
$deliveryAuthorization = ['ok' => false];
if ($deliveryRequested) {
    header('Cache-Control: no-store');
    if (!wants_json() || $isGuideRequest) {
        respond(false, 'Invalid internal QA request.', 403);
    }
    $deliveryAuthorization = bh_delivery_authorize($_POST, $_SERVER);
    if (($deliveryAuthorization['ok'] ?? false) !== true) {
        respond(false, 'Internal QA authorization failed. Do not retry.', 403);
    }
}
if (!$deliveryRequested) {
$qaRequested = $qaMode !== '' || $qaRunId !== '' || $utmSource === 'qa'
    || isset($_SERVER['HTTP_X_BLUEHAVEN_QA_SIGNATURE']) || isset($_POST['qa_signature']);
$signedQa = $qaRequested ? bh_verify_signed_qa($_POST, $_SERVER) : ['ok' => false];
if ($qaRequested && (($signedQa['ok'] ?? false) !== true
    || $qaMode !== 'developer' || !valid_qa_run_id($qaRunId) || $marketingConsent)) {
    security_failure_response('QA authorization failed. Generate a fresh signed request.', (string)($signedQa['status'] ?? 'qa_invalid_context'));
}
if (($signedQa['ok'] ?? false) === true) {
    $lead['qa_mode'] = 'developer';
    $lead['marketing_consent'] = 'no';
    if (!bh_security_record_review([
        'timestamp' => gmdate('c'),
        'mode' => 'qa',
        'status' => 'accepted',
        'decision' => 'qa_authorized',
        'lead_id' => $leadId,
        'email_hash' => bh_security_hash(bh_normalize_email($email)),
        'ip_hash' => bh_security_hash($lead['ip_address']),
        'message_hash' => bh_security_hash(bh_normalize_message($message)),
        'qa_run_id' => $qaRunId,
        'qa_timestamp' => $signedQa['timestamp'],
        'qa_nonce' => $signedQa['nonce'],
        'qa_payload_hash' => $signedQa['payload_hash'],
    ])) {
        respond(false, 'Security review storage is unavailable. Please try again later.', 503);
    }

    respond(true, security_submission_message([], true), 200, $leadId, true, null);
}

}

$turnstile = bh_turnstile_siteverify($_POST, $_SERVER);
if (($turnstile['ok'] ?? false) !== true) {
    [$httpStatus, $turnstileMessage] = security_turnstile_message((string)($turnstile['status'] ?? 'turnstile_invalid_token'));
    security_failure_response($turnstileMessage, (string)($turnstile['status'] ?? 'turnstile_invalid_token'), $httpStatus);
}

$risk = bh_security_rate_decision($lead, request_client_ip());
if (($risk['ok'] ?? false) !== true) {
    security_failure_response('Security review storage is unavailable. Please try again later.', (string)($risk['status'] ?? 'state_error'), 503);
}

if (!bh_security_record_review([
    'timestamp' => gmdate('c'),
    'mode' => 'turnstile',
    'status' => $risk['decision'] ?? 'accepted',
    'decision' => $risk['decision'] ?? 'accept',
    'lead_id' => $leadId,
    'email_hash' => bh_security_hash(bh_normalize_email($email)),
    'ip_hash' => bh_security_hash($lead['ip_address']),
    'message_hash' => $risk['normalized_message_hash'] ?? bh_security_hash(bh_normalize_message($message)),
    'lead' => ($risk['decision'] ?? '') === 'quarantine' ? $lead : null,
    'compound_reasons' => $risk['compound_reasons'] ?? [],
    'signal_flags' => $risk['signal_flags'] ?? [],
    'email_attempts' => $risk['email_attempts'] ?? 0,
    'ip_attempts' => $risk['ip_attempts'] ?? 0,
    'turnstile_hostname' => $turnstile['hostname'] ?? '',
    'turnstile_action' => $turnstile['action'] ?? '',
])) {
    respond(false, 'Security review storage is unavailable. Please try again later.', 503);
}

if (($risk['decision'] ?? 'accept') === 'quarantine') {
    respond(false, security_submission_message($risk, false), 429);
}

if (($deliveryAuthorization['ok'] ?? false) === true) {
    bh_delivery_finish($lead, $deliveryAuthorization, $turnstile);
}

$lead['qa_mode'] = '';
$lead['qa_run_id'] = '';
// Immutable submitted labels, separate from normalized routing/security fields.
$lead['raw_campaign_evidence'] = ['values'=>[], 'truncated'=>[]];
foreach (['utm_source','utm_medium','utm_campaign','utm_term','utm_content','source_page'] as $field) {
    $rawValue = $_POST[$field] ?? '';
    if (!is_string($rawValue)) $rawValue = '';
    $lead['raw_campaign_evidence']['values'][$field] = substr($rawValue,0,2000);
    if (strlen($rawValue)>2000) $lead['raw_campaign_evidence']['truncated'][] = $field;
}
// Only the server-held receipt is admitted; posted consent/cache fields are never trusted.
$lead['google_consent'] = bh_choice_for_lead(__DIR__ . '/data/consent-receipts',
    (string)($_COOKIE['__Host-bh_choice'] ?? ''), ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1', (int)floor(microtime(true)*1000));
if (($_POST['measurement_receipt_unresolved'] ?? '1') !== '0') $lead['google_consent'] = bh_choice_unknown();
$lead['mailerlite_status'] = '';
$mailerliteResult = mailerlite_sync_lead($lead);
$lead['mailerlite_status'] = mailerlite_result_message($mailerliteResult);

if (($mailerliteResult['ok'] ?? false) !== true && !str_starts_with($lead['mailerlite_status'], 'skipped:')) {
    error_log('MailerLite sync failed for ' . bh_security_hash(bh_normalize_email($email)) . ': provider_sync_failed');
}

append_lead($lead);
$attributionStored = bh_append_attribution(dirname(LEADS_CSV) . '/openai-attribution.csv', $lead);
if (!$attributionStored) error_log('OpenAI attribution persistence failed');
$crmResult = crm_sync_lead($lead);

if (($crmResult['ok'] ?? false) !== true && ($crmResult['status'] ?? '') !== 'not_configured') {
    log_crm_sync_failure($leadId, (string)($crmResult['status'] ?? 'failed'));
}

$rows = [
    ['label' => 'Lead ID', 'value' => $leadId, 'html' => false],
    ['label' => 'QA Run ID', 'value' => 'Not a QA test', 'html' => false],
    ['label' => 'Lead Type', 'value' => $leadType, 'html' => false],
    ['label' => 'Name', 'value' => $name !== '' ? $name : 'Not provided', 'html' => false],
    ['label' => 'Email', 'value' => $email !== '' ? $email : 'Not provided', 'html' => false],
    ['label' => 'Phone', 'value' => $phone !== '' ? $phone : 'Not provided', 'html' => false],
    ['label' => 'ZIP Code', 'value' => $zipcode !== '' ? $zipcode : 'Not provided', 'html' => false],
    ['label' => 'Project Type', 'value' => $projectType !== '' ? $projectType : 'Not provided', 'html' => false],
    ['label' => 'Project Details', 'value' => $message !== '' ? nl2br(h($message)) : 'Not provided', 'html' => $message !== ''],
    ['label' => 'Submitted From', 'value' => '<a href="' . h($sourcePage) . '">' . h($sourcePage) . '</a>', 'html' => true],
    ['label' => 'Page Title', 'value' => $pageTitle !== '' ? $pageTitle : 'Not provided', 'html' => false],
    ['label' => 'Form', 'value' => $formName !== '' ? $formName : 'Website Contact Form', 'html' => false],
    ['label' => 'Submitted At', 'value' => $submittedAt, 'html' => false],
    ['label' => 'Marketing Consent', 'value' => $marketingConsent ? 'Yes' : 'No', 'html' => false],
    ['label' => 'MailerLite', 'value' => $lead['mailerlite_status'], 'html' => false],
];

$emailBadge = 'New Website Lead';
$htmlBody = '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">'
    . '<div style="max-width:720px;margin:0 auto;padding:28px 16px;">'
    . '<div style="background:#0f2440;color:#fff;border-radius:18px 18px 0 0;padding:26px 28px;">'
    . '<div style="display:inline-block;background:#facc15;color:#0f172a;font-weight:700;padding:6px 10px;border-radius:999px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;">' . h($emailBadge) . '</div>'
    . '<h1 style="margin:16px 0 6px;font-size:28px;line-height:1.2;">Blue Haven Windows ' . h($leadType) . '</h1>'
    . '<p style="margin:0;color:#dbeafe;">A visitor submitted a form from ' . h($subjectPage ?: SITE_URL) . '.</p>'
    . '</div>'
    . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 18px 18px;border-collapse:separate;border-spacing:0;overflow:hidden;">'
    . build_rows($rows)
    . '</table>'
    . '<p style="font-size:12px;color:#64748b;margin:18px 4px 0;">This submission was also recorded to the local lead CSV for backup and MailerLite import. Reply directly to this email to contact the homeowner when an email address was provided.</p>'
    . '</div></body></html>';

$textBody = "Blue Haven Windows {$leadType}\n\n"
    . "Lead ID: {$leadId}\n"
    . "QA Run ID: Not a QA test\n"
    . "Name: " . ($name !== '' ? $name : 'Not provided') . "\n"
    . "Email: " . ($email !== '' ? $email : 'Not provided') . "\n"
    . "Phone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
    . "ZIP Code: " . ($zipcode !== '' ? $zipcode : 'Not provided') . "\n"
    . "Project Type: " . ($projectType !== '' ? $projectType : 'Not provided') . "\n"
    . "Project Details: " . ($message !== '' ? $message : 'Not provided') . "\n"
    . "Submitted From: {$sourcePage}\n"
    . "Page Title: " . ($pageTitle !== '' ? $pageTitle : 'Not provided') . "\n"
    . "Form: " . ($formName !== '' ? $formName : 'Website Contact Form') . "\n"
    . "Submitted At: {$submittedAt}\n"
    . "Marketing Consent: " . ($marketingConsent ? 'Yes' : 'No') . "\n"
    . "MailerLite: {$lead['mailerlite_status']}\n"
    . "Lead CSV: data/lead-submissions-v2.csv\n";

$sent = send_owner_email($subject, $htmlBody, $textBody, $email, $name);

if (!$sent) {
    respond(false, 'The message could not be sent. Please call (615) 987-0593 or email info@bluehavenview.com.', 500, null, false, crm_response_payload($crmResult));
}

if ($isGuideRequest) {
    if (!send_guide_email($email, $firstName)) {
        respond(false, 'Your request was received, but the guide email could not be sent. Please email info@bluehavenview.com or call (615) 987-0593.', 500, null, false, crm_response_payload($crmResult));
    }

    respond(true, 'Thank you. Please check your inbox for the free Window Buyer\'s Guide from Blue Haven Windows.', 200, $leadId, false, crm_response_payload($crmResult));
}

respond(true, 'Thank you. Your request has been emailed to Stephen at Blue Haven Windows.', 200, $leadId, false, crm_response_payload($crmResult), 'estimate');
