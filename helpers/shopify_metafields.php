<?php
/**
 * Shopify variant metafields — out of stock limit
 */

declare(strict_types=1);

/**
 * @return array{namespace: string, key: string, type: string}
 */
function getShopifyOosLimitMetafieldConfig(): array
{
    return [
        'namespace' => defined('SHOPIFY_OOS_LIMIT_NAMESPACE') ? SHOPIFY_OOS_LIMIT_NAMESPACE : 'custom',
        'key' => defined('SHOPIFY_OOS_LIMIT_KEY') ? SHOPIFY_OOS_LIMIT_KEY : 'out_of_stock_limit',
        'type' => defined('SHOPIFY_OOS_LIMIT_TYPE') ? SHOPIFY_OOS_LIMIT_TYPE : 'number_integer',
    ];
}

/**
 * Normalize metafield value for Shopify API
 */
function normalizeShopifyMetafieldValue(string $type, string $rawValue): string
{
    $rawValue = trim($rawValue);

    if ($type === 'number_integer') {
        return (string) max(0, (int) floor((float) $rawValue));
    }

    if ($type === 'number_decimal') {
        return number_format((float) $rawValue, 2, '.', '');
    }

    return $rawValue;
}

/**
 * Find existing variant metafield by namespace + key
 *
 * @return array<string, mixed>|null
 */
function getShopifyVariantMetafield(int $variantId, string $namespace, string $key): ?array
{
    $endpoint = sprintf(
        'variants/%d/metafields.json?namespace=%s&key=%s',
        $variantId,
        rawurlencode($namespace),
        rawurlencode($key)
    );

    $response = shopifyRequest('GET', $endpoint);

    if (!$response['success'] || !is_array($response['json']['metafields'] ?? null)) {
        return null;
    }

    foreach ($response['json']['metafields'] as $metafield) {
        if (!is_array($metafield)) {
            continue;
        }

        if (($metafield['namespace'] ?? '') === $namespace && ($metafield['key'] ?? '') === $key) {
            return $metafield;
        }
    }

    return null;
}

/**
 * Create or update a variant metafield
 *
 * @return array{success: bool, error: ?string}
 */
function setShopifyVariantMetafield(
    int $variantId,
    string $namespace,
    string $key,
    string $value,
    string $type
): array {
    $value = normalizeShopifyMetafieldValue($type, $value);
    $existing = getShopifyVariantMetafield($variantId, $namespace, $key);

    if ($existing !== null) {
        $metafieldId = (int) ($existing['id'] ?? 0);
        if ($metafieldId <= 0) {
            return ['success' => false, 'error' => 'Existing metafield has no ID'];
        }

        $response = shopifyRequest('PUT', 'metafields/' . $metafieldId . '.json', [
            'metafield' => [
                'id' => $metafieldId,
                'value' => $value,
                'type' => $type,
            ],
        ]);
    } else {
        $response = shopifyRequest('POST', 'metafields.json', [
            'metafield' => [
                'namespace' => $namespace,
                'key' => $key,
                'value' => $value,
                'type' => $type,
                'owner_resource' => 'variant',
                'owner_id' => $variantId,
            ],
        ]);
    }

    if (!$response['success']) {
        $error = 'HTTP ' . $response['status'] . ' — ' . substr((string) $response['body'], 0, 200);

        return ['success' => false, 'error' => $error];
    }

    return ['success' => true, 'error' => null];
}

/**
 * Set out-of-stock limit metafield on variant matched by CRM barcode
 *
 * @return array{success: bool, status: 'updated'|'not_found'|'error', message: string}
 */
function setShopifyOutOfStockLimitByBarcode(string $barcode, string $limitValue): array
{
    $barcode = normalizeSku($barcode);
    if ($barcode === '') {
        return ['success' => false, 'status' => 'error', 'message' => 'Empty barcode'];
    }

    $variant = resolveShopifyVariant($barcode);
    if ($variant === null) {
        return [
            'success' => false,
            'status' => 'not_found',
            'message' => 'Variant not found in Shopify (check SKU or barcode)',
        ];
    }

    $config = getShopifyOosLimitMetafieldConfig();
    $result = setShopifyVariantMetafield(
        $variant['variant_id'],
        $config['namespace'],
        $config['key'],
        $limitValue,
        $config['type']
    );

    if (!$result['success']) {
        return [
            'success' => false,
            'status' => 'error',
            'message' => $result['error'] ?? 'Metafield update failed',
        ];
    }

    return [
        'success' => true,
        'status' => 'updated',
        'message' => 'Metafield updated on variant ' . $variant['variant_id'],
    ];
}
