<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SyncStore.php';

$exact = new ExactApi($config);

try {
    $token = getValidExactToken($config, $exact);
    if (!$token) {
        redirectWithMessage('index.php', 'Connect to Exact Online first.', 'error');
    }
    $accessToken = (string)$token['access_token'];
    $division = getExactDivision($config, $exact, $accessToken);

    $pdo = Database::connection($config);
    $store = new SyncStore($pdo, Database::prefix($config));
} catch (Throwable $e) {
    appendConnectionLog('Customer sync setup failed: ' . $e->getMessage(), 'ERROR');
    redirectWithMessage('index.php', 'Customer sync could not start: ' . $e->getMessage(), 'error');
}

$prefix = Database::prefix($config);
$stmt = $pdo->query("
    SELECT customer_id, firstname, lastname, email, telephone
    FROM `{$prefix}customer`
    WHERE status = 1
    ORDER BY customer_id ASC
");
$customers = $stmt->fetchAll();

$batchSize = max(1, (int)$config['sync_batch_size']);
$rows = [];
$processed = 0;
$okCount = 0;
$failCount = 0;

foreach ($customers as $customer) {
    if ($processed >= $batchSize) {
        break;
    }

    $customerId = (int)$customer['customer_id'];
    if ($store->isSynced('customer', $customerId)) {
        continue;
    }

    $processed++;
    $email = trim((string)$customer['email']);
    $name = trim($customer['firstname'] . ' ' . $customer['lastname']);

    try {
        if ($email === '') {
            throw new RuntimeException('Customer has no email address, cannot match/create Exact account.');
        }

        $existing = $exact->findAccountByEmail($division, $email, $accessToken);
        if ($existing !== null) {
            $exactId = (string)$existing['ID'];
            $store->upsert('customer', $customerId, $exactId, 'ok', 'Linked to existing Exact account.');
            $okCount++;
            $rows[] = ['customer_id' => $customerId, 'name' => $name, 'email' => $email, 'status' => 'ok', 'message' => 'Linked existing account ' . $exactId];
            continue;
        }

        $created = $exact->createAccount($division, [
            'Name' => $name !== '' ? $name : $email,
            'Email' => $email,
            'Phone' => (string)$customer['telephone'],
            'IsSales' => true,
        ], $accessToken);

        $exactId = (string)($created['ID'] ?? '');
        if ($exactId === '') {
            throw new RuntimeException('Exact did not return an Account ID after creation.');
        }

        $store->upsert('customer', $customerId, $exactId, 'ok', 'Created new Exact account.');
        $okCount++;
        $rows[] = ['customer_id' => $customerId, 'name' => $name, 'email' => $email, 'status' => 'ok', 'message' => 'Created account ' . $exactId];
    } catch (Throwable $e) {
        $store->upsert('customer', $customerId, null, 'failed', $e->getMessage());
        $failCount++;
        $rows[] = ['customer_id' => $customerId, 'name' => $name, 'email' => $email, 'status' => 'failed', 'message' => $e->getMessage()];
    }
}

$_SESSION['sync_result'] = [
    'type' => 'customers',
    'processed' => $processed,
    'ok' => $okCount,
    'failed' => $failCount,
    'rows' => $rows,
];

appendConnectionLog("Customer sync run: processed={$processed}, ok={$okCount}, failed={$failCount}", $failCount > 0 ? 'ERROR' : 'INFO');

redirectWithMessage(
    'index.php',
    "Customer sync finished: {$okCount} ok, {$failCount} failed, {$processed} processed.",
    $failCount > 0 ? 'error' : 'ok'
);
