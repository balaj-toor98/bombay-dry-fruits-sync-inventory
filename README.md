# Bombay Dry Fruits — Inventory & Order Sync Middleware

Pure PHP + MySQL middleware to sync inventory and orders between **CRM**, **Shopify**, and **Foodpanda** (Delivery Hero Partner API).

## Folder structure

```
├── config/config.php       # API keys & DB credentials
├── cron/                   # Scheduled jobs
│   ├── fetch_crm.php       # Daily CRM → MySQL → Shopify + Foodpanda
│   ├── sync_shopify.php
│   └── sync_foodpanda.php
├── sync/                   # Standalone sync runners
├── webhooks/
│   ├── foodpanda_order.php       # Foodpanda → Shopify + DB stock
│   ├── foodpanda_catalog_job.php # Catalog bulk job results (async)
│   └── shopify_order.php         # Shopify → Foodpanda stock
├── helpers/                # Reusable functions
├── logs/                   # File logs (app.log)
├── dashboard/              # Simple monitoring UI
├── database.sql
└── .htaccess
```

## Requirements

- PHP 8.0+
- MySQL 5.7+ / MariaDB
- cURL, mysqli, json extensions
- Hostinger shared hosting (hPanel) or similar

## Installation (Hostinger)

### 1. Database

1. Open **phpMyAdmin** in hPanel.
2. Create database `bombay_inventory_sync` (or import `database.sql`).
3. Run the SQL from `database.sql`.

### 2. Upload files

Deploy via **GitHub** (Hostinger Git deployment) or FTP into `public_html/` (or a subfolder).

### 3. Configure

Edit `config/config.php`:

| Setting | Description |
|---------|-------------|
| `DB_*` | MySQL credentials from hPanel |
| `SHOPIFY_SHOP` | e.g. `bombay-dry-fruits.myshopify.com` |
| `SHOPIFY_ACCESS_TOKEN` | Admin API access token |
| `SHOPIFY_LOCATION_ID` | Inventory location ID (Settings → Locations) |
| `SHOPIFY_WEBHOOK_SECRET` | From Shopify webhook setup |
| `FOODPANDA_*` | Chain ID, Vendor ID, Bearer token from Partner Portal |
| `DASHBOARD_PASS` | Change default password |

### 4. CRON jobs (hPanel → Cron Jobs)

```bash
# Daily CRM fetch + full sync (2:00 AM)
0 2 * * * /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron/fetch_crm.php

# Optional: hourly Shopify-only sync
0 * * * * /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron/sync_shopify.php

# Optional: hourly Foodpanda-only sync
30 * * * * /usr/bin/php /home/USER/domains/DOMAIN/public_html/cron/sync_foodpanda.php
```

Replace paths with your Hostinger PHP path (`which php` in SSH).

### 5. Webhooks

| Platform | Event | URL |
|----------|-------|-----|
| Shopify | Order creation | `https://yourdomain.com/webhooks/shopify_order.php` |
| Foodpanda | New order | `https://yourdomain.com/webhooks/foodpanda_order.php` |
| Foodpanda | Catalog job results (recommended) | `https://yourdomain.com/webhooks/foodpanda_catalog_job.php` |

**Shopify:** Admin → Settings → Notifications → Webhooks → JSON format.

**Foodpanda:** Vendor Portal → Shop integrations → Webhook URL + Secret (chain-level).  
See [docs/FOODPANDA_CATALOG_API.md](docs/FOODPANDA_CATALOG_API.md) for Catalog API details from Partner documentation.

## Data flow

### Daily inventory

```
CRM API → fetchCRMData() → saveProductsToDB() → MySQL
                                              ↓
                              syncShopifyInventory() + syncFoodpandaInventory()
```

### Real-time orders

**Foodpanda order:**

1. Webhook receives JSON  
2. `createShopifyOrder()`  
3. `updateStock()` (reduce)  
4. `syncShopifyInventory()` for affected SKUs  

**Shopify order:**

1. HMAC validated  
2. `updateStock()` (reduce)  
3. `syncFoodpandaInventory()` for affected SKUs  
4. Skips orders tagged `foodpanda` (avoid double deduction)

## Core functions

| Function | File | Purpose |
|----------|------|---------|
| `fetchCRMData()` | `helpers/crm.php` | Pull CRM stock |
| `saveProductsToDB()` | `helpers/crm.php` | Upsert MySQL |
| `syncShopifyInventory()` | `helpers/shopify.php` | `inventory_levels/set.json` |
| `syncFoodpandaInventory()` | `helpers/foodpanda.php` | Catalog bulk PUT |
| `createShopifyOrder()` | `helpers/shopify.php` | Create Shopify order |
| `updateStock()` | `helpers/stock.php` | Adjust local stock |

## CRM mapping

| CRM field | DB column |
|-----------|-----------|
| `ProductId` | `product_id` |
| `ProductBarcode` | `sku` |
| `ProductName` | `name` |
| `LocationStock` | `stock` (negative → 0) |
| `ProductSalePrice` | `price` |

## Security

- `config/`, `helpers/`, `cron/`, `logs/` blocked via `.htaccess`
- Shopify webhooks: HMAC-SHA256 (`X-Shopify-Hmac-Sha256`)
- Dashboard: HTTP Basic Auth
- Use HTTPS on production
- Do not commit real `config.php` secrets to GitHub

## Dashboard

`https://yourdomain.com/dashboard/` — shows product count, sync times, low stock, recent logs.

## Foodpanda Catalog API (from Partner PDF)

- **Domain:** `https://foodpanda.partner.deliveryhero.io/`
- **Bulk updates:** `PUT /v2/chains/{chain_id}/vendors/{vendor_id}/catalog`
- **Existing SKUs only** — cannot create new products via this endpoint
- **`quantity`** is compared to a **sales buffer** (set by Account Manager in catalog, not API). If `quantity < sales_buffer`, the item is deactivated on the app.
- Set `FOODPANDA_SALES_BUFFER` in config to match your catalog
- Updates are **async** (`202` + `job_id`); register `foodpanda_catalog_job.php` webhook for per-SKU results
- Rate limit: **60 requests/minute** max per IP
- Changes may take **up to 30 minutes** on the customer-facing app

## Troubleshooting

- Check `logs/app.log` and `logs` table in phpMyAdmin.
- Ensure SKUs match across CRM, Shopify variants, and Foodpanda catalog.
- Shopify `SHOPIFY_LOCATION_ID` must match the location tied to your inventory.
- Foodpanda: check Vendor Portal → Assortment update jobs for failed SKUs.
- Optional cron: `cron/check_foodpanda_jobs.php` polls `GET /catalog/jobs/{job_id}`.

## License

Proprietary — Bombay Dry Fruits client project.
