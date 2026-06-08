<?php
declare(strict_types=1);

const CONTACT_RECIPIENT = 'info@bluehavenview.com';
const SITE_URL = 'https://bluehavenview.com';
const SITE_NAME = 'Blue Haven Windows';

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
$name = trim($firstName . ' ' . $lastName);

if ($name === '' && $email === '' && $phone === '') {
    respond(false, 'Please include your name, email, or phone number so Stephen can follow up.', 422);
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.', 422);
}

$submittedAt = (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('F j, Y g:i A T');
$subjectPage = $pageTitle !== '' ? $pageTitle : parse_url($sourcePage, PHP_URL_PATH);
$subject = 'Blue Haven Windows lead from ' . $subjectPage;

$rows = [
    ['label' => 'Name', 'value' => $name !== '' ? $name : 'Not provided', 'html' => false],
    ['label' => 'Email', 'value' => $email !== '' ? $email : 'Not provided', 'html' => false],
    ['label' => 'Phone', 'value' => $phone !== '' ? $phone : 'Not provided', 'html' => false],
    ['label' => 'Project Type', 'value' => $projectType !== '' ? $projectType : 'Not provided', 'html' => false],
    ['label' => 'Project Details', 'value' => $message !== '' ? nl2br(h($message)) : 'Not provided', 'html' => $message !== ''],
    ['label' => 'Submitted From', 'value' => '<a href="' . h($sourcePage) . '">' . h($sourcePage) . '</a>', 'html' => true],
    ['label' => 'Page Title', 'value' => $pageTitle !== '' ? $pageTitle : 'Not provided', 'html' => false],
    ['label' => 'Form', 'value' => $formName !== '' ? $formName : 'Website Contact Form', 'html' => false],
    ['label' => 'Submitted At', 'value' => $submittedAt, 'html' => false],
];

$tableRows = '';
foreach ($rows as $row) {
    $safeValue = $row['html'] ? $row['value'] : h($row['value']);
    $tableRows .= '<tr><th style="text-align:left;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;width:170px;color:#0f172a;vertical-align:top;">' . h($row['label']) . '</th><td style="padding:14px 16px;border-bottom:1px solid #e2e8f0;color:#334155;">' . $safeValue . '</td></tr>';
}

$htmlBody = '<!doctype html><html><body style="margin:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">'
    . '<div style="max-width:720px;margin:0 auto;padding:28px 16px;">'
    . '<div style="background:#0f2440;color:#fff;border-radius:18px 18px 0 0;padding:26px 28px;">'
    . '<div style="display:inline-block;background:#facc15;color:#0f172a;font-weight:700;padding:6px 10px;border-radius:999px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;">New Website Lead</div>'
    . '<h1 style="margin:16px 0 6px;font-size:28px;line-height:1.2;">Blue Haven Windows Contact Request</h1>'
    . '<p style="margin:0;color:#dbeafe;">A visitor submitted a form from ' . h($subjectPage ?: SITE_URL) . '.</p>'
    . '</div>'
    . '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;background:#fff;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 18px 18px;border-collapse:separate;border-spacing:0;overflow:hidden;">'
    . $tableRows
    . '</table>'
    . '<p style="font-size:12px;color:#64748b;margin:18px 4px 0;">Reply directly to this email to contact the homeowner when an email address was provided.</p>'
    . '</div></body></html>';

$textBody = "Blue Haven Windows Contact Request\n\n"
    . "Name: " . ($name !== '' ? $name : 'Not provided') . "\n"
    . "Email: " . ($email !== '' ? $email : 'Not provided') . "\n"
    . "Phone: " . ($phone !== '' ? $phone : 'Not provided') . "\n"
    . "Project Type: " . ($projectType !== '' ? $projectType : 'Not provided') . "\n"
    . "Project Details: " . ($message !== '' ? $message : 'Not provided') . "\n"
    . "Submitted From: {$sourcePage}\n"
    . "Page Title: " . ($pageTitle !== '' ? $pageTitle : 'Not provided') . "\n"
    . "Form: " . ($formName !== '' ? $formName : 'Website Contact Form') . "\n"
    . "Submitted At: {$submittedAt}\n";

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

$sent = mail(CONTACT_RECIPIENT, mb_encode_mimeheader($subject), $body, implode("\r\n", $headers));

if (!$sent) {
    respond(false, 'The message could not be sent. Please call (615) 987-0593 or email info@bluehavenview.com.', 500);
}

respond(true, 'Thank you. Your request has been emailed to Stephen at Blue Haven Windows.');
