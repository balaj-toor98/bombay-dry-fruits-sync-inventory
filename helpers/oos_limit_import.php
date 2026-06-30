<?php
/**
 * CSV import for Shopify out-of-stock limit metafields
 */

declare(strict_types=1);

/**
 * Parse CSV with barcode + limit columns
 *
 * @return array<int, array{barcode: string, limit: string}>
 */
function parseOutOfStockLimitCsv(string $filePath): array
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read CSV file');
    }

    $rows = [];
    $lineNumber = 0;

    while (($data = fgetcsv($handle)) !== false) {
        $lineNumber++;

        if (!is_array($data) || count($data) === 0) {
            continue;
        }

        $barcode = normalizeSku((string) ($data[0] ?? ''));
        $limit = trim((string) ($data[1] ?? ''));

        // Barcode is always text (letters, numbers, dashes, etc.) — never cast to int

        if ($barcode === '' && $limit === '') {
            continue;
        }

        // Skip header row
        if ($lineNumber === 1 && isBarcodeCsvHeader($barcode, $limit)) {
            continue;
        }

        if ($barcode === '') {
            continue;
        }

        $rows[] = [
            'barcode' => $barcode,
            'limit' => $limit,
        ];
    }

    fclose($handle);

    return $rows;
}

function isBarcodeCsvHeader(string $col1, string $col2): bool
{
    $combined = strtolower($col1 . ' ' . $col2);

    return str_contains($combined, 'barcode')
        || str_contains($combined, 'sku')
        || str_contains($col2, 'limit')
        || str_contains($col2, 'stock')
        || str_contains($col2, 'metafield');
}

/**
 * Process uploaded CSV and update Shopify variant metafields
 *
 * @return array{
 *   total: int,
 *   updated: int,
 *   not_found: int,
 *   failed: int,
 *   details: array<int, array{barcode: string, limit: string, status: string, message: string}>
 * }
 */
function importOutOfStockLimitCsv(string $filePath, int $maxRows = 5000): array
{
    $parsed = parseOutOfStockLimitCsv($filePath);

    if (count($parsed) === 0) {
        throw new RuntimeException('CSV is empty or has no valid rows');
    }

    if (count($parsed) > $maxRows) {
        throw new RuntimeException('CSV exceeds maximum of ' . $maxRows . ' rows');
    }

    loadShopifyInventoryCache(true);

    $updated = 0;
    $notFound = 0;
    $failed = 0;
    $details = [];

    foreach ($parsed as $row) {
        if ($row['limit'] === '') {
            $failed++;
            $details[] = [
                'barcode' => $row['barcode'],
                'limit' => $row['limit'],
                'status' => 'error',
                'message' => 'Missing limit value',
            ];
            continue;
        }

        $result = setShopifyOutOfStockLimitByBarcode($row['barcode'], $row['limit']);

        if ($result['status'] === 'updated') {
            $updated++;
        } elseif ($result['status'] === 'not_found') {
            $notFound++;
        } else {
            $failed++;
        }

        $details[] = [
            'barcode' => $row['barcode'],
            'limit' => $row['limit'],
            'status' => $result['status'],
            'message' => $result['message'],
        ];

        usleep(250000);
    }

    logSync(sprintf(
        'OOS limit CSV import: total=%d updated=%d not_found=%d failed=%d',
        count($parsed),
        $updated,
        $notFound,
        $failed
    ));

    return [
        'total' => count($parsed),
        'updated' => $updated,
        'not_found' => $notFound,
        'failed' => $failed,
        'details' => $details,
    ];
}

function sendOutOfStockLimitSampleCsv(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="out-of-stock-limit-sample.csv"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $out = fopen('php://output', 'w');
    if ($out === false) {
        http_response_code(500);
        exit('Could not open output stream');
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['barcode', 'out_of_stock_limit']);
    fputcsv($out, ['BD-F21026', '5']);
    fputcsv($out, ['6281100875093', '10']);
    fputcsv($out, ['SKU-ABC123', '3']);

    fclose($out);
    exit;
}
