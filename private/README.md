# Private config (not deployed from Git)

On **Hostinger**, create this file **on the server only** (via File Manager):

```
domains/your-site/private/config.php
```

One level **above** `public_html`, so Git auto-deploy never deletes it.

## Steps

1. File Manager → go to your domain folder (parent of `public_html`)
2. Create folder `private` if it does not exist
3. Copy `public_html/config/config.example.php` → `private/config.php`
4. Edit `private/config.php` with real DB, Shopify, Foodpanda credentials
5. Delete or ignore `public_html/config/config.php` (optional; bootstrap prefers `private/`)

After every `git push`, credentials in `private/config.php` stay safe.
