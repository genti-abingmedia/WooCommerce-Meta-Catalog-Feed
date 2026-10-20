# WooCommerce Meta Catalog Feed

A standalone WordPress plugin by **Gliterin** that builds a Meta catalog CSV in Albanian lek (ALL), with hourly background refreshes and a permanent public URL. It exports WooCommerce simple products and variations, brands, active discounts, images, and stock without OAuth or Meta API calls.

Install the ZIP from `dist/` using WordPress's plugin uploader. The plugin appears under **WooCommerce → Meta Catalog Feed**. Full installation, Commerce Manager setup, server cron, and cache instructions are in [SETUP.md](woocommerce-meta-catalog-feed/SETUP.md).

## Requirements and behavior

- WordPress 6.6+, WooCommerce 9.6+, PHP 8.1+.
- Automatic FlyCart Discount Rules 2.6+ integration for public single-item product and bulk discounts, including its Regular price/Sale price calculation setting. Additional cart/customer/coupon conditions, bundles, BOGO, and quantity-two-or-more offers are excluded.
- WooCommerce base currency ALL; the store's tax-display setting and base tax rates determine feed prices.
- Published, catalog-visible simple products and enabled variations. Out-of-stock items remain in the feed.
- One feed card and one permanent URL, with a dropdown for all products, selected products by name/SKU, categories, tags, or brands.
- Saved filters and product/category exclusions apply to hourly refreshes. Category descendants are configurable for inclusion and always excluded with an excluded ancestor.
- Per-product and per-variation exclusions; a parent exclusion applies to every variation.
- Plugin dates use Europe/Tirane with automatic daylight-saving changes, independently of WordPress timezone settings.
- WooCommerce `product_brand` names, alphabetically first when multiple are assigned; configurable BlissPharma fallback.
- Numeric product/variation IDs and variable-parent `item_group_id` values for a new catalog.
- Stable image URL revisions let Meta retry failed image fetches without re-uploading images or changing URLs every hour.
- Cached completed feeds, batches of 100 product IDs, durable retry artifacts, and preservation of the last successful generation after failure.
- Local generation status and paginated skipped-product reports. Meta import success must be verified in Commerce Manager.

The saved selection is snapshotted when each build starts. Saving a new selection cancels unfinished batches under the build lock and keeps the last completed feed available. Candidate membership is snapshotted as product IDs when each build starts. Only 100 product objects are processed per batch. Products added during a build appear in the next generation. Edits are read when each batch runs, so the full catalog is eventually consistent rather than a database transaction across all products.

## Build and test

```bash
find woocommerce-meta-catalog-feed -name '*.php' -print0 | xargs -0 -n1 php -l
bash scripts/test-integration.sh
python3 scripts/package.py
```

The integration suite creates an isolated Docker WordPress/MariaDB installation, pins WordPress 6.6.2, WooCommerce 9.6.2, and FlyCart Discount Rules core 2.6.18, and runs real product/tax/discount/selection/scheduler/file/HTTP/admin/uninstall checks. The licensed FlyCart Pro add-on is not bundled or available in the test environment; test the actual store rules on staging before importing production prices. It uses PHP 8.3 in the container. Syntax checks can also be run locally on PHP 8.1. Containers and test volumes are deleted automatically when the script exits.

Default test port is `127.0.0.1:18086`. Set `WCMCF_TEST_PORT` to change it. Set `WCMCF_KEEP_TEST_SITE=1` to retain the local test site for inspection. Override `WCMCF_TEST_WP_VERSION` and `WCMCF_TEST_WC_VERSION` to test another supported version. Start with clean test volumes when rerunning the entire setup:

```bash
docker compose -p wcmcf-integration -f tests/compose.yaml down --volumes
```

Optional headless browser checks exercise the dropdown, native WooCommerce product search, category selection, accessible labels, and mobile overflow. Set `WCMCF_PLAYWRIGHT_MODULE` to your Playwright module path and, if necessary, `WCMCF_BROWSER_PATH` to a compatible Chromium executable when running the integration script. Screenshots are written only to the ignored `tests/.runtime/` directory.

The deterministic packaging script includes only `woocommerce-meta-catalog-feed/`, excluding test credentials, fixtures, and development files. Generated ZIPs and test downloads are ignored by Git.

Licensed under GPL-2.0-or-later.
