# Foodpanda Partner API — Catalog Management

Summary aligned with **Partner API – Catalog Management Overview** (Delivery Hero).

## Platform domain

| Platform   | API base URL |
|-----------|--------------|
| Foodpanda | `https://foodpanda.partner.deliveryhero.io/` |

## Prerequisites

1. Partner onboarded on QC Local Shops stack  
2. **Vendor Portal** access  
3. Bearer token (chain-level, up to 11 tokens)  
4. Webhook URL + secret (recommended)

## Endpoints used by this middleware

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `PUT` | `/v2/chains/{chain_id}/vendors/{vendor_id}/catalog` | Bulk update **existing** SKUs |
| `POST` | `.../catalog/export` | Export full catalog (async) |
| `GET` | `.../catalog?query_term=` | Search/verify products |
| `GET` | `.../categories` | List categories |
| `GET` | `/v2/chains/{chain_id}/catalog/jobs/{job_id}` | Job status |

**Note:** Product **creation** uses `POST /v2/chains/{chain_id}/catalog` (different flow, Salesforce). This project only **updates** existing SKUs.

## Update payload fields

```json
{
  "products": [
    {
      "sku": "3671",
      "barcode": "6281100875093",
      "quantity": 3,
      "price": 10,
      "active": true,
      "maximum_sales_quantity": 5
    }
  ]
}
```

## Async flow (3 steps)

1. **Request** → `202` + `job_id`, `job_status: QUEUED`  
2. Processing → `IN_PROGRESS`  
3. **Webhook** (or Vendor Portal job list) → `COMPLETED` / `FAILED` + per-SKU CSV (`sku`, `state`, `errors`)

Register webhook: `https://yourdomain.com/webhooks/foodpanda_catalog_job.php`

## Quantity vs sales buffer (critical)

From Partner FAQ:

- **`quantity` is not stored** as inventory in Foodpanda  
- It is compared to a **sales buffer** set in the catalog (by Account Manager, not API)  
- If `quantity < sales_buffer` → product **deactivated** on customer app  
- Quantity-based deactivation must use **bulk** `PUT /catalog`, not single-product endpoints  

Configure `FOODPANDA_SALES_BUFFER` in `config.php` to match your catalog setting.

## Rate limits & timing

- **Max ~60 requests/minute** per IP (Cloudflare) — middleware uses 1.2s between calls  
- Frontend updates may take **up to 30 minutes** (job queue dependent)  
- Use **bulk** updates for many SKUs (one request up to ~100MB payload)  

## Vendor Portal checklist

- [ ] Generate API token (chain level)  
- [ ] Set webhook URL + secret  
- [ ] Confirm sales buffer with Account Manager  
- [ ] Review **Assortment update jobs** for failed SKUs  
- [ ] SKUs in ERP must already exist on Foodpanda catalog  

## Implementation files

| File | Role |
|------|------|
| `helpers/foodpanda.php` | API client, bulk sync, job status |
| `webhooks/foodpanda_catalog_job.php` | Async job result webhook |
| `webhooks/foodpanda_order.php` | Order → Shopify |
| `cron/sync_foodpanda.php` | Scheduled catalog sync |
| `cron/check_foodpanda_jobs.php` | Optional job polling |
