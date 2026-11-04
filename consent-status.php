<?php
declare(strict_types=1);
require_once __DIR__ . '/consent-ledger.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
// Read-only importer preflight. Reuses the EXISTING server/CRM shared token, never a new secret.
try {
    $config = require __DIR__ . '/.bluehaven-crm-config.php';
    $token = $config['token'] ?? '';
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($token) || $token === ''
        || !hash_equals('Bearer ' . $token, $authorization)) {
        http_response_code(403); echo '{"ok":false}'; exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    if (strlen($raw)>2048) throw new RuntimeException('input_bound');
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    $subject = $input['subject'] ?? '';
    if (!is_string($subject)) throw new RuntimeException('input_shape');
    $c = bh_choice_for_lead(__DIR__ . '/data/consent-receipts', $subject, false, (int)floor(microtime(true)*1000));
    echo json_encode(['ok'=>true,'consent'=>$c]);
} catch (Throwable $e) { http_response_code(503); echo '{"ok":false}'; }
