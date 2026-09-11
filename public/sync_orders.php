<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/SyncStore.php';

/** Finds or creates the Exact Account for an order, reusing the customer mapping when possible. */
function resolveAccountForOrder(
    ExactApi $exact,
    SyncStore $store,
    string $division,
    string $accessToken,
    int $customerId,
    string $email,
    string $name
): string {
    if ($customerId > 0) {
        $mapping = $store->find('customer', $customerId);
        if ($mapping !== null && $mapping['status'] === 'ok' && !empty($mapping['exact_id'])) {
            return (string)$mapping['exact_id'];
        }
    }

    if ($email === '') {
        throw new RuntimeException('Order has no customer email, cannot resolve Exact account.');
    }

    $existing = $exact->findAccountByEmail($division, $email, $accessToken);
    if ($existing !== null) {
        $accountId = (string)$existing['ID'];
    } else {
        $created = $exact->createAccount($division, [
            'Name' => $name !== '' ? $name : $email,
            'Email' => $email,
            'IsSales' => true,
        ], $accessToken);
        $accountId = (string)($created['ID'] ?? '');
        if ($accountId === '') {
            throw new RuntimeException('Exact did not return an Account ID after creation.');
        }
    }

    if ($customerId > 0) {
        $store->upsert('customer', $customerId, $accountId, 'ok', 'Linked via order sync.');
    }

    return $accountId;
}

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
    appendConnectionLog('Order sync setup failed: ' . $e->getMessage(), 'ERROR');
    redirectWithMessage('index.php', 'Order sync could not start: ' . $e->getMessage(), 'error');
}

$prefix = Database::prefix($config);
$testOrderId = isset($_GET['order_id']) && $_GET['order_id'] !== '' ? (int)$_GET['order_id'] : null;
$force = isset($_GET['force']);

if ($testOrderId !== null) {
    $stmt = $pdo->prepare("
        SELECT order_id, customer_id, firstname, lastname, email, total, order_status_id, date_added, currency_code
        FROM `{$prefix}order`
        WHERE order_id = :order_id
        LIMIT 1
    ");
    $stmt->execute(['order_id' => $testOrderId]);
    $orders = $stmt->fetchAll();
    if (empty($orders)) {
        redirectWithMessage('index.php', "Order #{$testOrderId} not found.", 'error');
    }
} else {
    $statusIds = $config['paid_order_status_ids'];
    if (!empty($statusIds)) {
        $placeholders = implode(',', array_fill(0, count($statusIds), '?'));
        $sql = "SELECT order_id, customer_id, firstname, lastname, email, total, order_status_id, date_added, currency_code
                FROM `{$prefix}order`
                WHERE order_status_id IN ({$placeholders})
                ORDER BY order_id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($statusIds);
    } else {
        $sql = "SELECT order_id, customer_id, firstname, lastname, email, total, order_status_id, date_added, currency_code
                FROM `{$prefix}order`
                WHERE order_status_id > 0
                ORDER BY order_id ASC";
        $stmt = $pdo->query($sql);
    }
    $orders = $stmt->fetchAll();
}

$lineStmt = $pdo->prepare("SELECT product_id, name, model, quantity, price FROM `{$prefix}order_product` WHERE order_id = :order_id");

$batchSize = $testOrderId !== null ? 1 : max(1, (int)$config['sync_batch_size']);
$rows = [];
$processed = 0;
$okCount = 0;
$failCount = 0;

foreach ($orders as $order) {
    if ($processed >= $batchSize) {
        break;
    }

    $orderId = (int)$order['order_id'];
    if (!$force && $store->isSynced('order', $orderId)) {
        continue;
    }

    $processed++;
    $email = trim((string)$order['email']);
    $name = trim($order['firstname'] . ' ' . $order['lastname']);

    try {
        $accountId = resolveAccountForOrder(
            $exact,
            $store,
            $division,
            $accessToken,
            (int)$order['customer_id'],
            $email,
            $name
        );

        $lineStmt->execute(['order_id' => $orderId]);
        $orderLines = $lineStmt->fetchAll();

        if (empty($orderLines)) {
            throw new RuntimeException('Order has no line items to invoice.');
        }

        $invoiceLines = [];
        foreach ($orderLines as $line) {
            $item = $exact->findItemByCode($division, trim((string)$line['model']), $accessToken);

            $invoiceLine = [
                'Quantity' => (float)$line['quantity'],
                'NetPrice' => (float)$line['price'],
            ];

            if ($item !== null) {
                $invoiceLine['Item'] = $item['ID'];
            } else {
                $invoiceLine['ItemDescription'] = (string)$line['name'];
                if ($config['default_gl_account_code'] !== '') {
                    $invoiceLine['GLAccount'] = $config['default_gl_account_code'];
                }
            }

            if ($config['default_vat_code'] !== '') {
                $invoiceLine['VATCode'] = $config['default_vat_code'];
            }

            $invoiceLines[] = $invoiceLine;
        }

        $payload = [
            'InvoiceTo' => $accountId,
            'OrderDate' => date('Y-m-d', strtotime((string)$order['date_added'])),
            'Description' => 'OpenCart Order #' . $orderId,
            'YourRef' => (string)$orderId,
            'SalesInvoiceLines' => $invoiceLines,
        ];

        if (!empty($order['currency_code'])) {
            $payload['Currency'] = $order['currency_code'];
        }

        $created = $exact->createSalesInvoice($division, $payload, $accessToken);
        $exactId = (string)($created['InvoiceID'] ?? $created['ID'] ?? '');
        $invoiceNumber = $created['InvoiceNumber'] ?? null;

        $store->upsert('order', $orderId, $exactId !== '' ? $exactId : null, 'ok', 'Invoice number: ' . ($invoiceNumber ?? 'n/a'));
        $okCount++;
        $rows[] = ['order_id' => $orderId, 'total' => $order['total'], 'status' => 'ok', 'message' => 'Invoice ' . ($invoiceNumber ?? $exactId)];
    } catch (Throwable $e) {
        $store->upsert('order', $orderId, null, 'failed', $e->getMessage());
        $failCount++;
        $rows[] = ['order_id' => $orderId, 'total' => $order['total'], 'status' => 'failed', 'message' => $e->getMessage()];
    }
}

$_SESSION['sync_result'] = [
    'type' => 'orders',
    'processed' => $processed,
    'ok' => $okCount,
    'failed' => $failCount,
    'rows' => $rows,
];

appendConnectionLog("Order sync run: processed={$processed}, ok={$okCount}, failed={$failCount}", $failCount > 0 ? 'ERROR' : 'INFO');

redirectWithMessage(
    'index.php',
    "Order sync finished: {$okCount} ok, {$failCount} failed, {$processed} processed.",
    $failCount > 0 ? 'error' : 'ok'
);
