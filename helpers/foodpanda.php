<?php
/**
 * Foodpanda / Delivery Hero Partner Catalog API
 *
 * Reference: Partner API – Catalog Management Overview (Delivery Hero)
 *
 * Key endpoints (Foodpanda domain):
 *   PUT  /v2/chains/{chain_id}/vendors/{vendor_id}/catalog     — update existing SKUs (bulk)
 *   POST /v2/chains/{chain_id}/vendors/{vendor_id}/catalog/export
 *   GET  /v2/chains/{chain_id}/vendors/{vendor_id}/catalog     — retrieve products
 *   GET  /v2/chains/{chain_id}/vendors/{vendor_id}/categories
 *   GET  /v2/chains/{chain_id}/catalog/jobs/{job_id}             — job status
 *
 * Important (from Partner FAQ):
 * - quantity is NOT stored; it is compared to a sales buffer configured in catalog (via Account Manager)
 * - if quantity < sales_buffer → product deactivated on frontend
 * - quantity-based deactivation must use bulk PUT /catalog (not single-product endpoint)
 * - Assortment API updates EXISTING products only (cannot create new SKUs)
 * - Updates are async: 202 + job_id → webhook reports per-SKU status (up to ~30 min on frontend)
 * - Rate limit: max ~60 requests/minute per IP (Cloudflare)
 */

declare(strict_types=1);

/** @var float|null Last Foodpanda API request timestamp */
$GLOBALS['_foodpanda_last_request_at'] = null;

/**
 * Catalog bulk update URL (product-bulk / assortment updates)
 */
function foodpandaCatalogUrl(): string
{
    return sprintf(
        '%s/v2/chains/%s/vendors/%s/catalog',
        rtrim(FOODPANDA_API_BASE, '/'),
        FOODPANDA_CHAIN_ID,
        FOODPANDA_VENDOR_ID
    );
}

/**
 * Catalog export URL
 */
function foodpandaCatalogExportUrl(): string
{
    return foodpandaCatalogUrl() . '/export';
}

/**
 * Catalog job status URL
 */
function foodpandaCatalogJobUrl(string $jobId): string
{
    return sprintf(
        '%s/v2/chains/%s/catalog/jobs/%s',
        rtrim(FOODPANDA_API_BASE, '/'),
        FOODPANDA_CHAIN_ID,
        $jobId
    );
}

/**
 * Enforce rate limit (Partner API: Cloudflare blocks > 60 req/min)
 */
function foodpandaRateLimitWait(): void
{
    $intervalMs = (int) FOODPANDA_MIN_REQUEST_INTERVAL_MS;
    $last = $GLOBALS['_foodpanda_last_request_at'];

    if ($last !== null) {
        $elapsed = (microtime(true) - $last) * 1000;
        if ($elapsed < $intervalMs) {
            usleep((int) (($intervalMs - $elapsed) * 1000));
        }
    }

    $GLOBALS['_foodpanda_last_request_at'] = microtime(true);
}

/**
 * Foodpanda API request with Bearer token
 */
function foodpandaRequest(string $method, string $url, ?array $payload = null): array
{
    foodpandaRateLimitWait();

    $options = [
        'bearer' => FOODPANDA_API_TOKEN,
        'headers' => ['Content-Type: application/json'],
    ];

    if ($payload !== null) {
        $options['json'] = $payload;
    }

    return httpRequest($method, $url, $options);
}

/**
 * Validate webhook using secret from Vendor Portal → Shop integrations → Webhook
 */
function validateFoodpandaWebhook(array $headers, string $rawBody): bool
{
    if (FOODPANDA_WEBHOOK_SECRET === '' || FOODPANDA_WEBHOOK_SECRET === 'your_foodpanda_webhook_secret') {
        return true;
    }

    $signature = $headers['x-signature']
        ?? $headers['x-webhook-signature']
        ?? $headers['authorization']
        ?? '';

    if (is_array($signature)) {
        $signature = $signature[0] ?? '';
    }

    $signature = (string) $signature;
    if (str_starts_with(strtolower($signature), 'bearer ')) {
        $signature = substr($signature, 7);
    }

    $expected = hash_hmac('sha256', $rawBody, FOODPANDA_WEBHOOK_SECRET);

    return hash_equals($expected, $signature);
}

/**
 * Build one catalog product payload per Partner API spec
 *
 * Attributes: sku, barcode, quantity, price, active, maximum_sales_quantity
 *
 * @param array<string, mixed> $p DB row or similar (sku, stock, price, barcode?)
 * @return array<string, mixed>|null
 */
function buildFoodpandaCatalogProduct(array $p): ?array
{
    $sku = trim((string) ($p['sku'] ?? ''));
    if ($sku === '') {
        return null;
    }

    $stock = max(0, (int) ($p['stock'] ?? 0));
    $buffer = max(0, (int) FOODPANDA_SALES_BUFFER);

    // Per FAQ: product deactivates when quantity < sales_buffer (bulk endpoint only)
    $isActive = $stock >= $buffer;

    $entry = [
        'sku' => $sku,
        'quantity' => $stock,
        'active' => $isActive,
    ];

    // CRM ProductBarcode (e.g. 1NO) — send as both sku and barcode for Foodpanda
    $barcode = trim((string) ($p['barcode'] ?? $p['sku'] ?? ''));
    if ($barcode !== '') {
        $entry['barcode'] = $barcode;
    }

    if (isset($p['price']) && (float) $p['price'] > 0) {
        $entry['price'] = (float) $p['price'];
    }

    if (isset($p['maximum_sales_quantity']) && (int) $p['maximum_sales_quantity'] > 0) {
        $entry['maximum_sales_quantity'] = (int) $p['maximum_sales_quantity'];
    } elseif (FOODPANDA_DEFAULT_MAX_SALES_QTY > 0) {
        $entry['maximum_sales_quantity'] = (int) FOODPANDA_DEFAULT_MAX_SALES_QTY;
    }

    return $entry;
}

/**
 * Bulk update existing catalog SKUs (PUT /catalog — async job)
 *
 * @param array<int, array<string, mixed>> $products
 * @return array{success: bool, job_id: ?string, job_status: ?string, updated: int, error: ?string, http_status: int}
 */
function foodpandaBulkUpdate(array $products): array
{
    if (count($products) === 0) {
        return [
            'success' => true,
            'job_id' => null,
            'job_status' => null,
            'updated' => 0,
            'error' => null,
            'http_status' => 0,
        ];
    }

    $catalogProducts = [];
    foreach ($products as $p) {
        $built = buildFoodpandaCatalogProduct($p);
        if ($built !== null) {
            $catalogProducts[] = $built;
        }
    }

    if (count($catalogProducts) === 0) {
        return [
            'success' => false,
            'job_id' => null,
            'job_status' => null,
            'updated' => 0,
            'error' => 'No valid SKUs in batch',
            'http_status' => 0,
        ];
    }

    $response = foodpandaRequest('PUT', foodpandaCatalogUrl(), [
        'products' => $catalogProducts,
    ]);

    // Bulk: 202 Accepted (async). Single-SKU inline update may return 200.
    $ok = $response['success'] || in_array($response['status'], [200, 202], true);

    if (!$ok) {
        $err = 'Foodpanda bulk update failed: HTTP ' . $response['status'] . ' — ' . $response['body'];
        logError($err);
        return [
            'success' => false,
            'job_id' => null,
            'job_status' => null,
            'updated' => 0,
            'error' => $err,
            'http_status' => $response['status'],
        ];
    }

    $json = $response['json'] ?? [];
    $jobId = (string) ($json['job_id'] ?? $json['jobId'] ?? '');
    $jobStatus = (string) ($json['job_status'] ?? $json['jobStatus'] ?? '');

    if ($jobId !== '') {
        saveFoodpandaJob($jobId, $jobStatus, count($catalogProducts));
    }

    logSync(sprintf(
        'Foodpanda PUT /catalog: %d SKUs, HTTP %d, job_id=%s, status=%s',
        count($catalogProducts),
        $response['status'],
        $jobId ?: 'n/a',
        $jobStatus ?: 'n/a'
    ));

    return [
        'success' => true,
        'job_id' => $jobId !== '' ? $jobId : null,
        'job_status' => $jobStatus !== '' ? $jobStatus : null,
        'updated' => count($catalogProducts),
        'error' => null,
        'http_status' => $response['status'],
    ];
}

/**
 * Persist catalog job for dashboard / debugging
 */
function saveFoodpandaJob(string $jobId, string $status, int $skuCount): void
{
    try {
        dbQuery(
            'INSERT INTO foodpanda_jobs (job_id, job_status, sku_count, created_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE job_status = VALUES(job_status), sku_count = VALUES(sku_count)',
            'ssi',
            [$jobId, $status, $skuCount]
        );
    } catch (Throwable $e) {
        // Table may not exist on older installs — log only
        logWarning('Could not save foodpanda_jobs: ' . $e->getMessage());
    }
}

/**
 * GET catalog job status
 * GET /v2/chains/{chain_id}/catalog/jobs/{job_id}
 *
 * @return array{success: bool, data: ?array, error: ?string}
 */
function getFoodpandaCatalogJobStatus(string $jobId): array
{
    $response = foodpandaRequest('GET', foodpandaCatalogJobUrl($jobId));

    if (!$response['success']) {
        return [
            'success' => false,
            'data' => null,
            'error' => 'HTTP ' . $response['status'] . ' — ' . $response['body'],
        ];
    }

    return ['success' => true, 'data' => $response['json'], 'error' => null];
}

/**
 * Export full vendor catalog snapshot (async — results via webhook)
 * POST /v2/chains/{chain_id}/vendors/{vendor_id}/catalog/export
 */
function exportFoodpandaCatalog(): array
{
    $response = foodpandaRequest('POST', foodpandaCatalogExportUrl(), []);

    $ok = $response['success'] || $response['status'] === 202;

    if (!$ok) {
        return [
            'success' => false,
            'job_id' => null,
            'error' => 'Export failed: HTTP ' . $response['status'],
        ];
    }

    $jobId = (string) ($response['json']['job_id'] ?? '');
    if ($jobId !== '') {
        saveFoodpandaJob($jobId, 'QUEUED', 0);
    }

    logSync('Foodpanda catalog export requested, job_id=' . ($jobId ?: 'n/a'));

    return [
        'success' => true,
        'job_id' => $jobId !== '' ? $jobId : null,
        'error' => null,
    ];
}

/**
 * Retrieve products from catalog (verify SKUs exist before sync)
 * GET /v2/chains/{chain_id}/vendors/{vendor_id}/catalog?query_term=...
 *
 * @return array<int, array<string, mixed>>
 */
function getFoodpandaCatalogProducts(?string $queryTerm = null, int $pageSize = 50): array
{
    $query = [
        'locale' => FOODPANDA_LOCALE,
        'page_size' => $pageSize,
        'page' => 1,
    ];

    if ($queryTerm !== null && $queryTerm !== '') {
        $query['query_term'] = $queryTerm;
    }

    $url = foodpandaCatalogUrl() . '?' . http_build_query($query);
    $response = foodpandaRequest('GET', $url);

    if (!$response['success'] || !is_array($response['json'])) {
        logWarning('Foodpanda catalog GET failed: HTTP ' . $response['status']);
        return [];
    }

    return $response['json']['products'] ?? $response['json']['data'] ?? [];
}

/**
 * GET vendor categories
 */
function getFoodpandaCategories(): array
{
    $url = sprintf(
        '%s/v2/chains/%s/vendors/%s/categories',
        rtrim(FOODPANDA_API_BASE, '/'),
        FOODPANDA_CHAIN_ID,
        FOODPANDA_VENDOR_ID
    );

    $response = foodpandaRequest('GET', $url);

    if (!$response['success']) {
        return [];
    }

    return $response['json']['categories'] ?? [];
}

/**
 * Sync inventory to Foodpanda via bulk PUT /catalog (existing SKUs only)
 *
 * @param array<int, array<string, mixed>>|null $products
 * @return array{success: int, failed: int, jobs: array<int, string>}
 */
function syncFoodpandaInventory(?array $products = null): array
{
    if ($products === null) {
        $products = getAllProductsForSync();
    }

    if (count($products) === 0) {
        logWarning('Foodpanda sync: no products');
        return ['success' => 0, 'failed' => 0, 'jobs' => []];
    }

    $chunks = array_chunk($products, FOODPANDA_BULK_CHUNK);
    $success = 0;
    $failed = 0;
    $jobs = [];

    logSync(sprintf(
        'Foodpanda sync: %d products, %d bulk request(s), sales_buffer=%d',
        count($products),
        count($chunks),
        FOODPANDA_SALES_BUFFER
    ));

    foreach ($chunks as $index => $chunk) {
        $result = foodpandaBulkUpdate($chunk);

        if ($result['success']) {
            $success += $result['updated'];
            if ($result['job_id']) {
                $jobs[] = $result['job_id'];
            }
        } else {
            $failed += count($chunk);
            logError('Foodpanda chunk ' . ($index + 1) . ' failed: ' . ($result['error'] ?? 'unknown'));
        }
    }

    updateSyncMeta('last_foodpanda_sync');
    logSync("Foodpanda sync queued: {$success} SKUs, {$failed} failed, jobs: " . implode(',', $jobs));

    return ['success' => $success, 'failed' => $failed, 'jobs' => $jobs];
}

/**
 * Handle catalog update job webhook (Step 3 — per-SKU results CSV or JSON)
 */
function processFoodpandaCatalogJobWebhook(array $payload): void
{
    $jobId = (string) ($payload['job_id'] ?? $payload['jobId'] ?? '');
    $status = (string) ($payload['status'] ?? $payload['job_status'] ?? '');

    if ($jobId !== '') {
        saveFoodpandaJob($jobId, $status, 0);
    }

    // Per-SKU results when webhook sends row data
    $results = $payload['results'] ?? $payload['products'] ?? [];
    if (is_array($results)) {
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = (string) ($row['sku'] ?? '');
            $state = (string) ($row['state'] ?? $row['status'] ?? '');
            $errors = $row['errors'] ?? null;

            if ($sku === '') {
                continue;
            }

            if ($state === 'failed' || ($errors !== null && $errors !== 'null' && $errors !== '')) {
                logError("Foodpanda catalog job {$jobId}: SKU {$sku} failed — " . json_encode($errors));
            } else {
                logSync("Foodpanda catalog job {$jobId}: SKU {$sku} → {$state}");
            }
        }
    }

    $downloadUrl = (string) ($payload['download_url'] ?? '');
    if ($downloadUrl !== '') {
        logInfo("Foodpanda catalog job {$jobId} log: {$downloadUrl}");
    }
}

/**
 * Parse Foodpanda order webhook → line items
 *
 * @return array<int, array{sku: string, quantity: int, title?: string, price?: float}>
 */
function parseFoodpandaOrderItems(array $payload): array
{
    $items = [];

    $products = $payload['products']
        ?? $payload['order']['products']
        ?? $payload['orderItems']
        ?? $payload['items']
        ?? [];

    foreach ($products as $p) {
        $sku = trim((string) ($p['sku'] ?? $p['barcode'] ?? $p['productId'] ?? ''));
        $qty = max(1, (int) ($p['quantity'] ?? $p['qty'] ?? 1));

        if ($sku !== '') {
            $items[] = [
                'sku' => $sku,
                'quantity' => $qty,
                'title' => (string) ($p['name'] ?? $p['title'] ?? $sku),
                'price' => isset($p['price']) ? (float) $p['price'] : null,
            ];
        }
    }

    if (count($items) === 0 && isset($payload['order']['line_items'])) {
        foreach ($payload['order']['line_items'] as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($sku !== '') {
                $items[] = [
                    'sku' => $sku,
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                    'title' => (string) ($line['name'] ?? $sku),
                    'price' => isset($line['unit_price']) ? (float) $line['unit_price'] : null,
                ];
            }
        }
    }

    return $items;
}

/**
 * Extract order reference from webhook payload
 */
function parseFoodpandaOrderId(array $payload): string
{
    return (string) (
        $payload['order_id']
        ?? $payload['orderId']
        ?? $payload['order']['id']
        ?? $payload['id']
        ?? uniqid('fp_', true)
    );
}
