# Sinalite WooCommerce

WooCommerce plugin to import Sinalite products using a staged workflow:

1. Pull API data into custom staging tables.
2. Process staged rows into WooCommerce simple/variable products.

This avoids web request timeouts by running heavy work with WP-CLI and optional cron batches.

## Features

- Admin settings page under `WooCommerce > Sinalite Import`
- API credentials and endpoint configuration
- Connection test buttons (Auth + Products)
- Staging tables:
  - `wp_sinalite_wc_products`
  - `wp_sinalite_wc_product_options`
  - `wp_sinalite_wc_variants`
- Rate-control support (`requests_per_min`) and retry handling for temporary API failures
- WP-CLI commands for DB setup, staging fetch, and staged processing
- Optional cron processing hook for incremental imports

## Setup

1. Activate plugin.
2. Open `WooCommerce > Sinalite Import`.
3. Fill:
   - Client ID
   - Client Secret
   - Audience
   - API base URL (default `https://liveapi.sinalite.com`)
   - Token URL (default `https://liveapi.sinalite.com/auth/token`)
   - Store code (`6` CA, `9` US)
4. Save settings and run test buttons.

## WP-CLI workflow

```bash
wp sinalite-wc db-install
wp sinalite-wc test-auth
wp sinalite-wc fetch --limit=50 --offset=0 --max-variant-pages=1
wp sinalite-wc process --batch=20 --max-variations=200
```

Single-cycle run:

```bash
wp sinalite-wc run-batch --fetch-limit=20 --process-batch=20
```

## Cron

Enable cron processing in plugin settings to run `sinalite_wc_import_cron` every 5 min, 15 min, or hourly.

Recommendation: keep fetches in WP-CLI jobs and use cron primarily for staged processing.
