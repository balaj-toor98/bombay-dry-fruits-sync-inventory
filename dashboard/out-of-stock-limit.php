<?php
/**
 * Dashboard: upload CSV to set Shopify variant out-of-stock limit metafield
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/bootstrap.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_layout.php';

@set_time_limit(0);
@ini_set('memory_limit', '512M');

if (isset($_GET['sample']) && $_GET['sample'] === 'csv') {
    sendOutOfStockLimitSampleCsv();
}

$metafieldConfig = getShopifyOosLimitMetafieldConfig();
$importResult = null;
$importError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
            throw new RuntimeException('No CSV file uploaded');
        }

        $file = $_FILES['csv'];
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code ' . $errorCode);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Invalid upload file');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== '' && $extension !== 'csv') {
            throw new RuntimeException('Please upload a .csv file');
        }

        $importResult = importOutOfStockLimitCsv($tmpPath);
    } catch (Throwable $e) {
        $importError = $e->getMessage();
        logError('OOS limit CSV import: ' . $importError);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Out of Stock Limit — Bombay Dry Fruits</title>
    <style><?= dashboardStyles() ?></style>
    <style>
        .upload-card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
            max-width: 640px;
        }
        .upload-card label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .upload-card input[type="file"] {
            display: block;
            width: 100%;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .upload-card button {
            padding: 10px 18px;
            background: #0066cc;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        .upload-card button:hover { background: #0052a3; }
        .meta-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .meta-info code {
            background: #eef2ff;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 13px;
        }
        .status-updated { color: #2e7d32; }
        .status-not_found { color: #b8860b; }
        .status-error { color: #c62828; }
    </style>
</head>
<body>
    <h1>Out of Stock Limit</h1>
    <p class="subtitle">Upload a CSV to set the Shopify variant metafield per barcode</p>

    <?php dashboardNav('oos-limit'); ?>

    <div class="meta-info">
        Metafield target:
        <code><?= htmlspecialchars($metafieldConfig['namespace']) ?></code> /
        <code><?= htmlspecialchars($metafieldConfig['key']) ?></code>
        (type: <code><?= htmlspecialchars($metafieldConfig['type']) ?></code>)
        <br>
        Configure namespace/key in <code>config.php</code> if your Shopify metafield differs.
    </div>

    <div class="notice">
        CSV format: column 1 = <strong>barcode</strong> (text — letters and numbers OK, e.g. <code>BD-F21026</code>, <code>SKU-ABC123</code>),
        column 2 = <strong>out of stock limit</strong> (integer only, e.g. <code>5</code>).
        Barcodes are matched against Shopify variant SKU or barcode field. Max 5000 rows per upload.
        <br>If using Excel, format the barcode column as <strong>Text</strong> before saving so leading zeros are not lost.
    </div>

    <?php if ($importError !== ''): ?>
        <div class="flash flash-error"><?= htmlspecialchars($importError) ?></div>
    <?php endif; ?>

    <?php if ($importResult !== null): ?>
        <div class="flash flash-<?= $importResult['failed'] > 0 || $importResult['not_found'] > 0 ? 'warning' : 'success' ?>">
            Processed <?= (int) $importResult['total'] ?> row(s):
            <?= (int) $importResult['updated'] ?> updated,
            <?= (int) $importResult['not_found'] ?> not found in Shopify,
            <?= (int) $importResult['failed'] ?> failed.
        </div>
    <?php endif; ?>

    <div class="dashboard-section">
        <div class="upload-card">
            <form method="post" enctype="multipart/form-data">
                <label for="csv">Upload CSV</label>
                <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
                <div class="toolbar">
                    <button type="submit">Upload &amp; Update Metafields</button>
                    <a class="btn-export" href="?sample=csv">Download sample CSV</a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($importResult !== null && count($importResult['details']) > 0): ?>
    <div class="dashboard-section">
        <h2>Import results</h2>
        <table>
            <thead>
                <tr>
                    <th>Barcode</th>
                    <th>Limit</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($importResult['details'] as $detail): ?>
                <tr>
                    <td><?= htmlspecialchars($detail['barcode']) ?></td>
                    <td><?= htmlspecialchars($detail['limit']) ?></td>
                    <td class="status-<?= htmlspecialchars($detail['status']) ?>">
                        <?= htmlspecialchars($detail['status']) ?>
                    </td>
                    <td><?= htmlspecialchars($detail['message']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</body>
</html>
