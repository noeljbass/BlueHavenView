<?php
declare(strict_types=1);
require_once __DIR__ . '/consent-ledger.php';
require_once __DIR__ . '/consent-transport.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
// Same-origin JSON only; this endpoint never accepts a lead, stage, or arbitrary subject ID.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://bluehavenview.com'
    || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
    || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
    http_response_code(403); echo '{"ok":false}'; exit;
}
try {
    $raw = file_get_contents('php://input', false, null, 0, 4097);
    if (strlen($raw) > 4096) throw new RuntimeException('input_bound');
    $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('input_shape');
    $subject = $_COOKIE['__Host-bh_choice'] ?? '';
    if (!is_string($subject) || preg_match('/^[a-f0-9]{64}$/D', $subject) !== 1) $subject = bin2hex(random_bytes(32));
    // Existing configuration is loaded only on the deployed server; no new secret or credential discovery.
    $deliver = function(array $event, int $budget): bool {
        try {
            $config = require __DIR__ . '/.bluehaven-crm-config.php';
            return is_array($config) && bh_gas_deliver($event,$config,$budget,'bh_gas_http');
        } catch (Throwable $e) { return false; }
    };
    setcookie('__Host-bh_choice', $subject, ['expires'=>time()+180*86400,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
    $s = bh_choice_store(__DIR__ . '/data/consent-receipts', $subject, $input, (int)floor(microtime(true)*1000),
        ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1' || ($input['gpc'] ?? false) === true, $deliver);
    echo json_encode(['ok'=>$s['acknowledged'],'measurementEligible'=>$s['acknowledged'] && !$s['restricted'] && $s['status']==='GRANTED']);
} catch (Throwable $e) { http_response_code(503); echo '{"ok":false}'; }
