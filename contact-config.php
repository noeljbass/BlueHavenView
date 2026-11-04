<?php
declare(strict_types=1);

require_once __DIR__ . '/contact-security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

$config = bh_turnstile_config();
$payload = [
    'version' => '20260906',
    'turnstile_sitekey' => $config['sitekey'],
    'turnstile_action' => $config['action'],
    'turnstile_hostname' => $config['hostname'],
    'turnstile_enabled' => $config['sitekey'] !== '' && $config['action'] !== '' && $config['hostname'] !== '',
    'turnstile_timeout' => $config['timeout'],
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
