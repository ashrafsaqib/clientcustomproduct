<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$rawBody = file_get_contents('php://input') ?: '';

$signatureHeader = $_SERVER['HTTP_X_EXACT_SIGNATURE']
    ?? $_SERVER['HTTP_EXACT_SIGNATURE']
    ?? '';

if ($config['webhook_secret'] !== '' && $signatureHeader !== '') {
    $expected = hash_hmac('sha256', $rawBody, $config['webhook_secret']);
    if (!hash_equals($expected, trim($signatureHeader))) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid webhook signature']);
        exit;
    }
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = ['raw' => $rawBody];
}

$logPath = dirname(__DIR__) . '/storage/webhook.log';
$line = date('c') . ' ' . json_encode($payload) . PHP_EOL;
file_put_contents($logPath, $line, FILE_APPEND);

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
