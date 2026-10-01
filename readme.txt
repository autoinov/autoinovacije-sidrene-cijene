=== Autoinovacije – Sidrene cijene i cjenici ===
Contributors: eosmanagic
Tags: woocommerce, pricing, csv, xml, croatia
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reference prices and public CSV/XML price lists for WooCommerce products and variations.

== Description ==

Autoinovacije – Sidrene cijene i cjenici is a WooCommerce plugin for managing historical reference prices ("sidrene cijene") separately from current, regular, sale and lowest 30-day prices.

The plugin is intended primarily for Croatian WooCommerce stores. Its administration interface is currently available in Croatian.

= Main features =

* A separate reference price, reference date, source, lock status and audit history for each product or variation.
* Reference-price display on product pages and, optionally, in product archives and search results.
* Reference-price information in the cart and checkout without changing WooCommerce price calculations.
* Manual entry, confirmed copying of existing WooCommerce prices, and CSV/XLSX import.
* Downloadable CSV and XLSX templates populated with product IDs, variation IDs, SKUs and EANs.
* Dry-run validation before imported data is applied.
* Product/service classification with an automatic WooCommerce virtual-product default and a manual override.
* Explicit per-item public-feed inclusion controls; password-protected items are never published.
* Combined and separate product/service CSV and XML price lists with an archive retained for at least 31 days.
* Daily WP-Cron publication, a WP-CLI command and a paginated public REST endpoint backed by the last generated static feed.
* Product and variation filters for items with a reference price, without one, and requiring manual review.
* Declared compatibility with WooCommerce HPOS and Cart/Checkout Blocks.

= Reference-price data =

Reference-price data is stored in standard WordPress post meta. Settings and operational status are stored in the WordPress options table. The plugin does not create custom database tables.

The plugin does not automatically treat today's price as a historical price. An administrator must either import documented historical data, enter it manually, or explicitly confirm that an existing WooCommerce price matches the selected reference date. Existing reference prices are not overwritten by this confirmation tool.

= Public price lists =

Generated files are stored below the standard WordPress uploads directory in `ai-sidrene-cijene`. The plugin creates a public archive page at `/ai-cijenici/`, which can be changed through normal WordPress page editing.

The public REST endpoint is available at `/wp-json/ai-sidrene/v1/prices` and supports the `page` and `per_page` parameters. It reads the last generated public CSV and does not query the live WooCommerce catalogue on public requests. Password-protected items are always excluded. Catalogue-hidden items are excluded by default and can be included globally or explicitly per item.

= Important notice =

This plugin is a technical publishing and data-management tool. It does not provide legal or tax advice and does not guarantee compliance with any law or official interpretation. Store owners remain responsible for choosing the correct reference dates, verifying historical prices, reviewing generated files, and obtaining professional advice where necessary.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin ZIP through **Plugins > Add New Plugin > Upload Plugin**, or install it from the WordPress.org Plugin Directory.
3. Activate **Autoinovacije – Sidrene cijene i cjenici**.
4. Open **Tools > Sidrene cijene i cjenici**.
5. Enter the shop and retail-location details and review the currency, brand source and EAN meta key.
6. Enter reference prices manually, explicitly confirm suitable existing prices, or import a completed CSV/XLSX template.
7. Generate the first CSV/XML price list and verify the public archive.
8. For reliable publication before a fixed deadline, configure a real server cron job instead of relying only on visitor-triggered WP-Cron.

== Frequently Asked Questions ==

= Does the plugin change WooCommerce product prices or order totals? =

No. Reference prices are stored separately and are added only as informational output. The plugin does not replace the current, regular or sale price and does not modify cart totals.

= Is a reference price the same as the lowest price in the previous 30 days? =

No. They are separate data concepts. This plugin does not modify another plugin's lowest-price records or calculations.

= Does the plugin create custom database tables? =

No. It uses WordPress post meta and options.

= Can I import my own price list? =

Yes. Download a pre-filled CSV or XLSX template from the plugin administration page, enter the reference date, price and source, run a dry-run validation, and then explicitly apply the file.

= Does WP-Cron guarantee that the price list is published at exactly 07:30? =

No. WordPress WP-Cron is triggered by site traffic. Configure a real system cron job when an exact publication deadline matters.

= What happens when the plugin is uninstalled? =

Reference-price, audit and archive data is intentionally retained to avoid accidental loss of business records. Administrators can first download a complete backup and then use the explicitly confirmed permanent-removal procedure under **Tools > Sidrene cijene i cjenici**. Permanent removal deletes the plugin settings and operational status, all reference-price and audit metadata, the public archive page and generated CSV/XML files, and then deactivates the plugin.

== Privacy ==

The plugin does not contact Autoinovacije AI or any other external service, does not use tracking, and does not transmit telemetry. It stores shop settings, product reference-price data and audit entries on the site's own server.

When enabled and generated, CSV/XML feeds, the archive page and the REST endpoint are public by design. They can include product names, identifiers, prices, availability, product URLs and the configured business-location details. Administrators should review these values before publication.

== WP-CLI ==

Generate and publish the current CSV/XML files:

`wp ai-sidrene generate`

== Changelog ==

= 1.1.2 =
* Improved the reference-price help icon and added a compact, theme-resistant 16px tooltip.
* Added a complete administrator backup containing settings, reference-price metadata, audit records and generated CSV/XML files.
* Added an explicitly confirmed permanent-removal procedure that deletes plugin data, public files and the archive page before deactivating the plugin.

= 1.1.1 =
* Fixed WP-CLI command registration compatibility.
* Reworked product-review queries so all selectable values use prepared placeholders.

= 1.1.0 =
* Added explicit product/service classification and public-feed inclusion controls for products and variations.
* Excluded password-protected items and excluded catalogue-hidden items by default.
* Changed the public REST endpoint to read the generated static feed instead of querying the live product catalogue.
* Extended CSV/XLSX templates and imports with item type and publication controls while retaining compatibility with older templates.
* Confirmed coexistence with WooCommerce Lowest Price: its 30-day value remains separate and its earlier price filter is preserved.
* Adopted the distinctive Autoinovacije plugin identity for WordPress.org submission.

= 1.0.4 =
* Prepared the plugin metadata, licensing and documentation for the WordPress.org Plugin Directory.
* Added a clear privacy section and legal-compliance disclaimer.
* Declared WooCommerce as a required plugin dependency.

= 1.0.3 =
* Added aligned lists for products with a reference price, without one, and requiring manual review.
* Included simple products and individual variations with search and direct editing links.

= 1.0.2 =
* Moved the public archive to the unique `/ai-cijenici/` slug.

= 1.0.1 =
* Migrated the legacy archive shortcode without visible shortcode text or duplicate content.
* Improved the public archive display.

= 1.0.0 =
* Initial production release by Autoinovacije AI.
* Added reference-price metadata, CSV/XLSX templates, dry-run import, public CSV/XML feeds, REST and WP-CLI support.
* Added migration, atomic publication, archive retention and the administration dashboard.
