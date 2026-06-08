<?php
declare(strict_types=1);

const CONTACT_RECIPIENT = 'info@bluehavenview.com';
const SITE_URL = 'https://bluehavenview.com';
const SITE_NAME = 'Blue Haven Windows';
const GUIDE_FILE = __DIR__ . '/WindowsBuyersGuide.pdf';
const GUIDE_FILE_NAME = 'Blue-Haven-Windows-Buyers-Guide.pdf';
const LEADS_CSV = __DIR__ . '/data/lead-submissions.csv';

require_once __DIR__ . '/mailerlite.php';

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function respond(bool $success, string $message, int $statusCode = 200): void
{
    http_response_code($statusCode);

    if (wants_json()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    $heading = $success ? 'Thank you!' : 'Something went wrong';
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><meta name=\"theme-color\" content=\"#facc15\"><title>{$safeHeading} | " . SITE_NAME . "</title></head><body style=\"font-family:Arial,sans-serif;max-width:720px;margin:48px auto;padding:0 20px;line-height:1.6\"><h1>{$safeHeading}</h1><p>{$safeMessage}</p><p><a href=\"/\">Return to " . SITE_NAME . "</a></p></body></html>";
    exit;
}

function clean(string $key, int $maxLength = 1000): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    return mb_substr($value, 0, $maxLength);
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
                'lead_type',
                'first_name',
                'last_name',
                'name',
                'email',
                'phone',
                'project_type',
                'message',
                'source_page',
                'page_title',
                'form_name',
                'ip_address',
                'user_agent',
                'marketing_consent',
                'mailerlite_status',
            ]);
        }

        fputcsv($handle, [
            $lead['submitted_at_iso'],
            $lead['lead_type'],
            $lead['first_name'],
            $lead['last_name'],
            $lead['name'],
            $lead['email'],
            $lead['phone'],
            $lead['project_type'],
            $lead['message'],
            $lead['source_page'],
            $lead['page_title'],
            $lead['form_name'],
            $lead['ip_address'],
            $lead['user_agent'],
            $lead['marketing_consent'] ?? '',
            $lead['mailerlite_status'] ?? '',
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

function send_owner_email(string $subject, string $htmlBody, string $textBody, string $email, string $name): bool
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

    return mail(CONTACT_RECIPIENT, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));
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

if (clean('website') !== '') {
    respond(true, 'Thank you. Your request has been received.');
}

$firstName = clean('first_name', 80);
$lastName = clean('last_name', 80);
$email = clean('email', 180);
$phone = clean('phone', 80);
$message = clean('message', 3000);
$projectType = clean('project_type', 180);
$pageTitle = clean('page_title', 180);
$formName = clean('form_name', 180);
$sourcePage = clean_url(clean('source_page', 500) ?: ($_SERVER['HTTP_REFERER'] ?? SITE_URL));
$marketingConsent = marketing_consent_granted(clean('marketing_consent', 20));
$name = trim($firstName . ' ' . $lastName);
$isGuideRequest = is_guide_request($formName, $projectType, $message);

if ($name === '' && $email === '' && $phone === '') {
    respond(false, 'Please include your name, email, or phone number so Stephen can follow up.', 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}

if ($isGuideRequest && $email === '') {
    respond(false, 'Please enter your email address so we can send the free guide.', 422);
}

$submittedAtDate = new DateTimeImmutable('now', new DateTimeZone('America/Chicago'));
$submittedAt = $submittedAtDate->format('F j, Y g:i A T');
$submittedAtIso = $submittedAtDate->format(DateTimeInterface::ATOM);
$subjectPage = $pageTitle !== '' ? $pageTitle : parse_url($sourcePage, PHP_URL_PATH);
$leadType = $isGuideRequest ? 'Free Guide Request' : 'Contact Request';
$subject = 'Blue Haven Windows ' . strtolower($leadType) . ' from ' . $subjectPage;

$lead = [
    'submitted_at_iso' => $submittedAtIso,
    'lead_type' => $leadType,
    'first_name' => $firstName,
    'last_name' => $lastName,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'project_type' => $projectType,
    'message' => $message,
    'source_page' => $sourcePage,
    'page_title' => $pageTitle,
    'form_name' => $formName,
    'ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80),
    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300),
    'marketing_consent' => $marketingConsent ? 'yes' : 'no',
];
$mailerliteResult = mailerlite_sync_lead($lead);
$lead['mailerlite_status'] = mailerlite_result_message($mailerliteResult);

if (($mailerliteResult['ok'] ?? false) !== true && !str_starts_with($lead['mailerlite_status'], 'skipped:')) {
    error_log('MailerLite sync failed for ' . ($email !== '' ? $email : 'unknown email') . ': ' . $lead['mailerlite_status']);
}

append_lead($lead);

$rows = [
    ['label' => 'Lead Type', 'value' => $leadType, 'html' => false],
    ['label' => 'Name', 'value' => $name !== '' ? $name : 'Not provided', 'html' => false],
    ['label' => 'Email', 'value' => $email !== '' ? $email : 'Not provided', 'html' => false],
    ['label' => 'Phone', 'value' => $phone !== '' ? $phone : 'Not provided', 'html' => false],
    ['label' => 'Project Type', 'value' => $projectType !== '' ? $projectType : 'Not provided', 'html' => false],
    ['label' => 'Project Details', 'value' => $message !== '' ? nl2br(h($message)) : 'Not provided', 'html' => $message !== ''],
    ['label' => 'Submitted From', 'value' => '<a href="' . h($sourcePage) . '">' . h($sourcePage) . '</a>', 'html' => true],
    ['label' => 'Page Title', 'value' => $pageTitle !== '' ? $pageTitle : 'Not provided', 'html' => false],
    ['label' => 'Form', 'value' => $formName !== '' ? $formName : 'Website Contact Form', 'html' => false],
    ['label' => 'Submitted At', 'value' => $submittedAt, 'html' => false],
    ['label' => 'Marketing Consent', 'value' => $marketingConsent ? 'Yes' : 'No', 'html' => false],
    ['label' => 'MailerLite', 'value' => $lead['mailerlite_status'], 'html' => false],
];

$htmlBody = '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">'
    . '<div style="max-width:720px;margin:0 auto;padding:28px 16px;">'
    . '<div style="background:#0f2440;color:#fff;border-radius:18px 18px 0 0;padding:26px 28px;">'
    . '<div style="display:inline-block;background:#facc15;color:#0f172a;font-weight:700;padding:6px 10px;border-radius:999px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;">New Website Lead</div>'
    . '<h1 style="margin:16px 0 6px;font-size:28px;line-height:1.2;">Blue Haven Windows ' . h($leadType) . '</h1>'
    . '<p style="margin:0;color:#dbeafe;">A visitor submitted a form from ' . h($subjectPage ?: SITE_URL) . '.</p>'
    . '</div>'
    . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 18px 18px;border-collapse:separate;border-spacing:0;overflow:hidden;">'
    . build_rows($rows)
    . '</table>'
    . '<p style="font-size:12px;color:#64748b;margin:18px 4px 0;">This submission was also recorded to the local lead CSV for backup and MailerLite import. Reply directly to this email to contact the homeowner when an email address was provided.</p>'
    . '</div></body></html>';

$textBody = "Blue Haven Windows {$leadType}\n\n"
    . "Name: " . ($name !== '' ? $name : 'Not provided') . "\n"
    . "Email: " . ($email !== '' ? $email : 'Not provided') . "\n"
    . "Phone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
    . "Project Type: " . ($projectType !== '' ? $projectType : 'Not provided') . "\n"
    . "Project Details: " . ($message !== '' ? $message : 'Not provided') . "\n"
    . "Submitted From: {$sourcePage}\n"
    . "Page Title: " . ($pageTitle !== '' ? $pageTitle : 'Not provided') . "\n"
    . "Form: " . ($formName !== '' ? $formName : 'Website Contact Form') . "\n"
    . "Submitted At: {$submittedAt}\n"
    . "Marketing Consent: " . ($marketingConsent ? 'Yes' : 'No') . "\n"
    . "MailerLite: {$lead['mailerlite_status']}\n"
    . "Lead CSV: data/lead-submissions.csv\n";

$sent = send_owner_email($subject, $htmlBody, $textBody, $email, $name);

if (!$sent) {
    respond(false, 'The message could not be sent. Please call (615) 987-0593 or email info@bluehavenview.com.', 500);
}

if ($isGuideRequest) {
    if (!send_guide_email($email, $firstName)) {
        respond(false, 'Your request was received, but the guide email could not be sent. Please email info@bluehavenview.com or call (615) 987-0593.', 500);
    }

    respond(true, 'Thank you. Please check your inbox for the free Window Buyer\'s Guide from Blue Haven Windows.');
}

respond(true, 'Thank you. Your request has been emailed to Stephen at Blue Haven Windows.');
