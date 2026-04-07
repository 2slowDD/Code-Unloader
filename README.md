# Code Unloader v1.4.2

Per-page JavaScript & CSS asset management for WordPress. Surgically dequeue scripts and styles on any page using exact, wildcard, or regex URL rules.

## Key Features

* Disable any registered JS or CSS file on any page or post
* Exact URL, wildcard pattern (`/shop/*`), and full regex matching
* Rules survive cache flushes and plugin reactivations
* Assets grouped by plugin, theme, or WordPress Core in the panel
* Per-page frontend panel accessible from the Admin Toolbar
* Access panel on any page via `?wpcu` URL parameter
* Global admin screen listing all rules across the site
* One-click kill switch to instantly restore all assets sitewide
* Bypass all rules for a single request via `?nowpcu` URL parameter
* Rule groups with enable/disable toggle and "View Rules" modal (50 rules per page)
* Conditional rules (logged-in users, WooCommerce pages, shortcodes, post types)
* Device-type rules (desktop-only or mobile-only)
* Inline script/style blocking for assets without registered handles
* Inline block detection — see every inline `<script>` and `<style>` on the page
* Full audit log of all changes
* JSON import/export
* Cache purge integration (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Autoptimize, Breeze, SG Optimizer, Nginx Helper, Cloudflare)
* Zero performance overhead on pages with no matching rules

## Requirements

* WordPress 6.2 or higher
* PHP 8.0 or higher

## Installation

1. Upload the plugin files to `/wp-content/plugins/code-unloader`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Visit any page while logged in as an admin and click **Assets** in the Admin Toolbar
4. Toggle off any asset — a rule creation dialog will appear

## Compatibility

WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, WooCommerce, Elementor, Divi, WP Bakery, basically everything WP related.

## License

GPL-2.0-or-later — see [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
