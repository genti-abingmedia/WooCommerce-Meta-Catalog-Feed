# Set up the ALL catalog feed

## Install and generate

Upload `woocommerce-meta-catalog-feed-1.2.1.zip` under **Plugins → Add New Plugin → Upload Plugin**, then activate it.

Requirements: WordPress 6.6+, WooCommerce 9.6+, PHP 8.1+, a writable WordPress uploads directory, and working WooCommerce Scheduled Actions. Activate separately on each site; network activation is blocked.

Keep **WooCommerce → Settings → General → Currency** set to **Albanian lek (ALL)**. Open **WooCommerce → Meta Catalog Feed**, choose **Generate products by**, confirm the fallback brand, and select **Save & generate CSV**. **Refresh now** rebuilds the current saved selection. Wait until the status is **Ready** and inspect exported and skipped counts. Skipped items list their product ID and reason; fix their missing price, image, or URL and refresh again.

Use the permanent URL displayed on that page:

```text
https://blisspharma.al/?wcmcf_feed=1
```

The actual URL is generated from the installed site's home URL. A test/staging installation produces its own URL automatically.

## Choose products in the single feed

One feed card controls one permanent URL. Changing the dropdown changes the CSV contents at that same URL; no extra feeds or URLs are created.

- **All products:** include every eligible product.
- **Selected products:** search by product name or SKU and choose individual products or variations. Selecting a variable parent includes its enabled variations.
- **Categories:** choose one or more categories. **Include child categories** controls whether descendants are included.
- **Tags:** include products assigned to any selected product tag. Variations use their parent's tags.
- **Brands:** include products assigned to any selected WooCommerce brand. Variations inherit the parent's brands unless they have their own assignment. The fallback brand affects the CSV brand text; it does not assign a product to a brand filter.

Open **Exclude products or categories** to omit products or entire categories. Category exclusions always include descendants. Exclusions take priority in every mode, including the product's **Exclude from Meta feed** checkbox. A parent exclusion covers every variation.

Specific selection modes require at least one selection. If no selected products are eligible, a completed CSV may contain only its header. Review the exported count before Meta imports it, since full replacement imports can remove items absent from the feed.

**Save & generate CSV** stores the selection for every hourly refresh and supersedes unfinished work. The previous completed CSV remains available until the new generation succeeds. Changing settings does not require editing the Meta URL.

Plugin dates display **Tirana time (Europe/Tirane)**, with automatic daylight-saving adjustments. This does not change WordPress's timezone setting or the hourly interval.

### Upgrade from an earlier version

Upload the 1.2.1 ZIP and choose WordPress's option to replace the existing plugin. Do not delete the old plugin first, because deletion runs uninstall cleanup. The existing URL, fallback brand, product exclusions, and completed CSV are preserved. Saved selections are preserved. Upgrading from 1.0.0 keeps **All products** as the default until you save a different selection.

After upgrading, use **Refresh now** to rebuild the CSV, wait for completion, then update the data source in Meta Commerce Manager. Version 1.2.1 adds a stable `wcmcf_image` query parameter to image URLs so Meta sees a new URL and can retry previously failed image fetches. No image re-upload is required. The parameter stays fixed across hourly builds and plugin releases, and changes when the selected attachment URL or modification date changes. Image replacements made outside WordPress must also update the attachment modification date to change this revision. Existing cached CSVs receive the new URLs only after a successful rebuild. Eligibility must be checked in Commerce Manager after image processing.

## Discount Rules Pro / FlyCart discounts

Version 1.2.0 automatically uses FlyCart's product discount API when Discount Rules for WooCommerce 2.6+ is active. No extra URL or feed card is created. The regular shop-display price remains in `price`; the eligible discounted shop-display price is written to `sale_price`. Native WooCommerce sales continue to work when no rule applies.

Supported rules are **Product Adjustment** and **Bulk Discount** that apply to a single item, with no additional rule conditions. Product/category/tag/attribute filters remain supported through FlyCart's engine. Rule priorities and the **Calculate discount from: Regular price / Sale price** setting are respected, so the feed does not apply a native sale or a rule discount twice. Store-base tax display is applied to the raw rule price once.

The feed evaluates quantity **1**, with an empty cart. It excludes rules with coupon, cart subtotal, customer, user role, login, or other additional conditions, as well as BOGO, bundles, cart adjustments, and free-shipping offers. These cannot be represented as a price available to every Meta shopper. Bulk prices requiring two or more items are excluded. Disabled, deleted, future, expired, and exhausted rules do not contribute a discount.

For the Momcozy cleaning kit example, an active public 10% rule should produce:

```text
id:         73449
price:      3800.00 ALL
sale_price: 3420.00 ALL
```

After upgrading, select **Refresh now**, wait for **Ready**, and inspect product 73449 in the downloaded CSV. Then trigger the existing data source's import in Commerce Manager or wait for its next hourly fetch. Check Meta's `Sale price` after ingestion. If the field remains blank in the CSV, check the rule type, single-item quantity, dates, filters, and additional conditions. A lower promotional price displayed only for a larger quantity is not a single-item catalog price.

An invalid result or exception from the discount API fails the generation and preserves the previous completed CSV. The plugin calculates rule prices without writing them back to WooCommerce products or changing checkout rules. The integration suite exercises FlyCart core 2.6.18; the site's licensed Pro extension and actual rules still need staging verification.

Developer API: [FlyCart documentation](https://docs.flycart.org/en/articles/7907342-developer-documentation).

## Connect Meta Commerce Manager

1. In your new product catalog, open **Data sources** and add a **Data feed**.
2. Select the scheduled URL option, paste the plugin's permanent URL, and leave authentication fields empty.
3. Choose an **hourly** schedule and start the initial import.
4. Check import diagnostics for ALL prices, product IDs, images, brands, stock, and variations. The supplied ALL test was accepted, but the full feed still needs validation in this catalog.
5. Use the data source's full-feed replacement/removal behavior so products absent from a later feed are removed from Meta. Confirm that behavior with a test product; exact interface labels may vary.

Use only this feed as the source for these catalog items. The plugin uses the WooCommerce product or variation ID as `id`, and the variable parent ID as `item_group_id`. If you later add Pixel/CAPI catalog tracking, those event IDs must match this convention.

Meta fetching and WordPress generating are separate steps. A product edit appears after the next successful generation and subsequent Meta fetch; two hourly schedules can produce nearly two hours of delay, plus Meta processing time.

## Cache, server access, and timing

Exclude requests containing `wcmcf_feed=1` from WordPress page caches and CDN cache rules. Allow the URL and product/image URLs through maintenance screens, login protection, and bot challenges. The feed response includes `Cache-Control: no-store`, but caches ahead of WordPress need their own exclusion.

For reliable background work, have the server run WordPress's due cron events every minute under the site's filesystem owner. For example, with WP-CLI installed:

```cron
* * * * * /usr/bin/flock -n /tmp/blisspharma-wp-cron.lock /usr/local/bin/wp --path=/absolute/path/to/wordpress cron event run --due-now --quiet >> /absolute/path/to/cron.log 2>&1
```

Adapt the executable paths and site path to your hosting environment. Once the server job works, add this to `wp-config.php` to disable traffic-triggered cron:

```php
define( 'DISABLE_WP_CRON', true );
```

On hosts without WP-CLI, ask the host to trigger `wp-cron.php` regularly through its scheduler. WordPress's [system scheduler documentation](https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/) describes this option. Plugin jobs are grouped under `wcmcf` in **WooCommerce → Status → Scheduled Actions**; build failures also appear in the `wcmcf` WooCommerce log.

The working files live under `wp-content/uploads/wcmcf-feed/`. Apache `.htaccess` and IIS `web.config` rules deny direct access. On Nginx, add a corresponding rule for the actual uploads URL if you want to keep temporary artifacts inaccessible:

```nginx
location ^~ /wp-content/uploads/wcmcf-feed/ {
    deny all;
}
```

WordPress serves the public CSV endpoint by reading completed files internally. Temporary files contain product information but must not be used as a Meta data source.

## Validate before production use

- Import a staging catalog containing simple products, variations with different attributes/images, product brands, and a discounted product.
- Confirm `price` is the regular shop-display price and `sale_price` is the active discount. Future or expired native sales do not contribute a discount; an active eligible FlyCart rule may still populate `sale_price`.
- Test a public Discount Rules product discount, a quantity-one bulk rule, and a quantity-two or coupon rule that should be excluded. Verify the CSV and Meta sale prices.
- Change a product's title, price, image, and stock. Refresh, then run a Meta import and verify each change.
- Test selected-product, category (including descendants), tag, and brand modes at the same URL.
- Exclude a product or delete a test product. Confirm it disappears from the generated feed and is removed from the Meta data source after import.
- Check that out-of-stock items remain with `out of stock`, and backordered items use `available for order`.
- Verify the URL works without browser cookies or login and returns fresh CSV through the production cache/CDN.

Generation status reflects local CSV generation. Only Commerce Manager's import results confirm ingestion and product eligibility. The plugin leaves checkout in WooCommerce and does not alter store currency, prices, or Meta account settings.

## Troubleshooting

**HTTP 503:** The first build has not finished, WooCommerce is unavailable, or the base currency is not ALL. Inspect the settings page and Scheduled Actions. A missing completed file also returns 503, without exposing filesystem paths.

**Last refresh failed:** The prior successful feed remains available. Fix the displayed configuration, storage, or scheduling error and select **Refresh now**. After 15 minutes without progress, an abandoned build restarts at the next refresh.

**No new rows:** Check the saved selection and exclusions first. Only published simple products and enabled variations whose parent is published and catalog-visible are exported. Hidden/search-only products, exclusions, drafts, and unsupported product types are omitted. Invalid required data is listed separately as skipped.

**Old prices:** Check last successful generation, server cron, and cache exclusions. Saving a selection or fallback brand queues a new generation. Refresh now uses the saved settings.

**Uninstall:** Deactivation stops jobs and discards unfinished builds while retaining completed files/settings. Deleting the plugin removes its data and exclusion metadata. If filesystem permissions prevent deletion, remove the plugin-owned `wcmcf-feed` directory after resolving those permissions.
