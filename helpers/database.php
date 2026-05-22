<?php
/**
 * MySQL database connection (mysqli + prepared statements)
 */

declare(strict_types=1);

/** @var mysqli|null */
$GLOBALS['_db_connection'] = null;

/**
 * Get shared mysqli connection
 */
function getDB(): mysqli
{
    if ($GLOBALS['_db_connection'] instanceof mysqli) {
        return $GLOBALS['_db_connection'];
    }

    $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($db->connect_error) {
        throw new RuntimeException('Database connection failed: ' . $db->connect_error);
    }

    $db->set_charset(DB_CHARSET);
    $GLOBALS['_db_connection'] = $db;

    return $db;
}

/**
 * Execute prepared statement and return result
 *
 * @param string $sql
 * @param string $types e.g. 'ssi'
 * @param array $params
 * @return mysqli_result|bool
 */
function dbQuery(string $sql, string $types = '', array $params = []): mysqli_result|bool
{
    $db = getDB();
    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException('Prepare failed: ' . $db->error);
    }

    if ($types !== '' && count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Execute failed: ' . $error);
    }

    $result = $stmt->get_result();
    if ($result !== false) {
        $stmt->close();
        return $result;
    }

    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected >= 0;
}

/**
 * Fetch single row as associative array
 */
function dbFetchOne(string $sql, string $types = '', array $params = []): ?array
{
    $result = dbQuery($sql, $types, $params);
    if ($result instanceof mysqli_result) {
        $row = $result->fetch_assoc();
        $result->free();
        return $row ?: null;
    }
    return null;
}

/**
 * Fetch all rows
 * @return array<int, array<string, mixed>>
 */
function dbFetchAll(string $sql, string $types = '', array $params = []): array
{
    $result = dbQuery($sql, $types, $params);
    if (!($result instanceof mysqli_result)) {
        return [];
    }
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->free();
    return $rows;
}

/**
 * Update sync_meta timestamp column
 */
function updateSyncMeta(string $column): void
{
    $allowed = ['last_crm_fetch', 'last_shopify_sync', 'last_foodpanda_sync'];
    if (!in_array($column, $allowed, true)) {
        return;
    }
    $sql = "UPDATE sync_meta SET `{$column}` = NOW() WHERE id = 1";
    dbQuery($sql);
}
