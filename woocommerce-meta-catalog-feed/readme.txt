=== WooCommerce Meta Catalog Feed ===
Tags: woocommerce, meta, facebook, catalog, product feed
Requires at least: 6.6
Tested up to: 6.6
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.2.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

By Gliterin. An hourly Meta catalog CSV in ALL with a permanent public URL, variations, discounts, stock, and per-product exclusions.

== Description ==

Designed for WooCommerce stores whose base currency is Albanian lek (ALL).
WooCommerce 9.6+ is required. Checkout and store prices remain in WooCommerce.
The plugin refreshes its single feed using WooCommerce's bundled Action Scheduler.
Meta downloads only a completed CSV and does not need WordPress login or OAuth.

One feed card and one permanent URL support all products, selected products by name/SKU,
categories, tags, or WooCommerce brands. Exclude individual products or categories.
All plugin timestamps display Europe/Tirane with automatic daylight-saving changes.

The feed exports eligible published, catalog-visible simple products and enabled variations.
It includes out-of-stock products, even if WooCommerce hides those products in the shop.
Product brands fall back to a configurable store brand, initially BlissPharma.
Variable parents are exported as groups through item_group_id, without duplicate parent rows.

FlyCart Discount Rules 2.6+ (including Pro) is detected automatically. Public
single-item Product Adjustment and Bulk Discount prices populate sale_price.
Rules with additional conditions, coupon/customer/cart offers, BOGO, bundles,
and bulk tiers requiring more than one item are excluded. See SETUP.md.

Feed columns: id, title, description, availability, condition, link, image_link,
brand, price, sale_price, item_group_id.

Generation success does not confirm Meta import success. Check Commerce Manager's
import diagnostics. This plugin does not bypass Meta's currency or product eligibility rules.

== Installation ==

1. Upload the plugin ZIP under Plugins > Add New Plugin > Upload Plugin.
2. Activate with WordPress 6.6+, PHP 8.1+, and WooCommerce 9.6+.
3. Set WooCommerce's base currency to ALL.
4. Open WooCommerce > Meta Catalog Feed, choose products, and check the fallback brand.
5. Select Save & generate CSV. Refresh now rebuilds the saved selection.
6. Copy the permanent URL into a scheduled Meta Commerce Manager data feed.
7. Set Meta's import schedule to hourly and verify the initial import diagnostics.

Exclude products through the Exclude from Meta feed checkbox in Product data > General.
Variations also have their own checkbox. Parent exclusions cover all child variations.

== Frequently Asked Questions ==

= Where is the feed? =

The plugin displays the site's permanent URL, ending in ?wcmcf_feed=1.
On blisspharma.al it is https://blisspharma.al/?wcmcf_feed=1.

= Why is the feed not ready? =

The endpoint returns HTTP 503 with Retry-After: 300 before the first completed feed,
when WooCommerce is unavailable, or when the base currency is not ALL.
Use WooCommerce > Meta Catalog Feed to see generation errors and skipped items.

= What happens if a refresh fails? =

The previous successful CSV remains available. An abandoned build is restarted by
the next hourly or manual refresh after 15 minutes without progress.

= Does hourly mean real-time? =

No. The feed refresh and Meta's fetch are separate schedules. WordPress WP-Cron
depends on visits. Set up a reliable server cron job if timing matters. See SETUP.md.

= Do I need to exclude the URL from caching? =

Yes. Exclude requests containing wcmcf_feed=1 from page caches, CDN cache rules,
maintenance pages, login protection, and bot challenges. The plugin sends no-cache
headers, but a cache or proxy can intercept the request before WordPress runs.

= How are taxes handled? =

Prices use the shop's inclusive/exclusive tax-display setting and the store's base
tax rates, independently of the visiting customer's address or exemption.

= Are Meta tracking and OAuth included? =

No. This is a scheduled feed plugin. It does not create Pixels, send conversion
events, or call Meta's API. Numeric WooCommerce IDs are used in this new catalog.

= What gets removed on uninstall? =

Plugin settings, scheduled jobs, feed artifacts, and exclusion metadata.
WooCommerce products, orders, prices, and currency settings are preserved.
Deactivation keeps settings and completed feed files, but stops refreshes.

== Changelog ==

= 1.2.0 =
* Export public single-item FlyCart Discount Rules prices in sale_price.
* Respect native sales, rule pricing base, active dates, usage limits, and shop tax display.
* Isolate discount calculations from worker cart contents and preserve completed CSVs on API errors.

= 1.1.0 =
* Single feed card with all-products, product-name/SKU, category, tag, and brand selection.
* Product/category exclusions and safe replacement of superseded builds.
* Tirana timestamps, responsive administration, URL copy, and CSV download.

= 1.0.0 =
* Initial release: ALL feed, variations, product brands, stock, discounts,
  hourly background generation, status reporting, exclusions, and safe publication.
