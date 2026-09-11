<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/ExactApi.php';
require_once __DIR__ . '/../src/Database.php';

$exact = new ExactApi($config);

try {
    $token = getValidExactToken($config, $exact);
    if (!$token) {
        redirectWithMessage('index.php', 'Connect to Exact Online first.', 'error');
    }
    $accessToken = (string)$token['access_token'];
    $division = getExactDivision($config, $exact, $accessToken);

    $pdo = Database::connection($config);
} catch (Throwable $e) {
    appendConnectionLog('Stock sync setup failed: ' . $e->getMessage(), 'ERROR');
    redirectWithMessage('index.php', 'Stock sync could not start: ' . $e->getMessage(), 'error');
}

$prefix = Database::prefix($config);

try {
    $items = $exact->getItemsStock($division, $accessToken);
} catch (Throwable $e) {
    appendConnectionLog('Stock sync failed to fetch Exact items: ' . $e->getMessage(), 'ERROR');
    redirectWithMessage('index.php', 'Stock sync failed to fetch Exact items: ' . $e->getMessage(), 'error');
}

$findStmt = $pdo->prepare("SELECT product_id, quantity FROM `{$prefix}product` WHERE model = :code OR sku = :code LIMIT 1");
$updateStmt = $pdo->prepare("UPDATE `{$prefix}product` SET quantity = :quantity, date_modified = NOW() WHERE product_id = :product_id");

$updated = 0;
$unchanged = 0;
$notFound = 0;
$rows = [];

foreach ($items as $item) {
    $code = trim((string)($item['Code'] ?? ''));
    if ($code === '' || !isset($item['CurrentStock'])) {
        continue;
    }

    $stock = (int)round((float)$item['CurrentStock']);

    $findStmt->execute(['code' => $code]);
    $product = $findStmt->fetch();

    if ($product === false) {
        $notFound++;
        continue;
    }

    if ((int)$product['quantity'] === $stock) {
        $unchanged++;
        continue;
    }

    $updateStmt->execute(['quantity' => $stock, 'product_id' => $product['product_id']]);
    $updated++;
    $rows[] = ['product_id' => $product['product_id'], 'code' => $code, 'old_quantity' => $product['quantity'], 'new_quantity' => $stock];
}

$_SESSION['sync_result'] = [
    'type' => 'stock',
    'updated' => $updated,
    'unchanged' => $unchanged,
    'not_found' => $notFound,
    'rows' => $rows,
];

appendConnectionLog("Stock sync run: updated={$updated}, unchanged={$unchanged}, not_found={$notFound}", 'INFO');

redirectWithMessage(
    'index.php',
    "Stock sync finished: {$updated} updated, {$unchanged} unchanged, {$notFound} not matched."
);
