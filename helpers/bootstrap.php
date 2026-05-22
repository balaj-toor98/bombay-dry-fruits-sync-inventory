<?php
/**
 * Bootstrap: load config and all helpers
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/crm.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/foodpanda.php';
