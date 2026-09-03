=== Code Unloader – Unload CSS & JavaScript Per Page ===
Contributors: dalibord
Tags: unload css, unload javascript, asset manager, script manager, dequeue
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.4.11
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Unload unused plugin CSS and JavaScript by page without code. Reduce HTTP requests and page weight with safe, reversible asset rules.

== Description ==

Code Unloader is a free WordPress asset manager that lets you unload unused plugin CSS and JavaScript on selected pages without writing PHP. Reduce HTTP requests and page weight by loading files only where they are needed, with a one-click kill switch and per-request bypass for quick rollback.

One WordPress.org user unloaded eight JavaScript files and two CSS files from the homepage, reducing it by 634.2 KB and improving PageSpeed from 96 to 99. Results vary by site and depend on which assets can be safely unloaded.

Read the review:
[https://wordpress.org/support/topic/very-good-increases-pagespeed/](https://wordpress.org/support/topic/very-good-increases-pagespeed/)

= Common uses =

* Unload contact-form CSS and JavaScript from pages without a form
* Stop WooCommerce assets from loading on pages that do not use shop features
* Unload slider, sharing, popup, or page-builder files where they are not needed
* Create different asset rules for desktop and mobile visitors
* Apply rules to one URL, a group of URLs, post types, or conditional page types

= How it works =

1. Open any frontend page while logged in as an administrator.
2. Click **⚡ Assets** in the WordPress Admin Toolbar.
3. Find a CSS or JavaScript file and switch it off.
4. Choose where the rule should apply: an exact URL, wildcard pattern, regular expression, device type, or condition.
5. Save the rule, clear page cache if needed, and test the page.

The frontend panel groups assets by plugin, theme, or WordPress Core, making it easier to see what is loading and where it came from.

[youtube https://youtu.be/abCdOEl1cxg]

Official plugin homepage:
[https://wpservice.pro/our-products/code-unloader/](https://wpservice.pro/our-products/code-unloader/)

= Main features =

* Unload registered CSS and JavaScript on selected pages or across matching URLs
* Exact URL, wildcard (`/shop/*`), and regular-expression matching
* Conditions for logged-in users, WooCommerce pages, shortcodes, and post types
* Desktop-only and mobile-only asset rules
* Rule groups, global rule management, audit log, and JSON import/export
* One-click kill switch that restores all assets without deleting any rules
* Per-request `?nowpcu` bypass for testing the unoptimized page
* Detection of inline `<script>` and `<style>` blocks for easier investigation

= Safe, reversible testing =

Every rule can be disabled or deleted. If an unloaded file affects the page, append `?nowpcu` to the URL to bypass all Code Unloader rules for that request. You can also use the global kill switch to restore every asset sitewide while keeping your rules saved.

Test unloading changes on a staging site first whenever possible. Removing a required CSS or JavaScript file can affect a page's appearance or functionality.

= Compatibility =

Code Unloader is designed to work alongside common caching, optimization, e-commerce, and page-builder plugins, including WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, WooCommerce, Elementor, Divi, and WPBakery Page Builder.

PHP 8.0 or higher is required.

== Installation ==

1. In WordPress, go to **Plugins > Add New Plugin** and search for **Code Unloader**.
2. Click **Install Now**, then **Activate**.
3. Visit any frontend page while logged in as an administrator.
4. Click **⚡ Assets** in the Admin Toolbar.
5. Switch off an asset and choose where the unloading rule should apply.
6. Test the page's design and functionality after saving the rule.

== Frequently Asked Questions ==

= Does Code Unloader remove unused CSS inside a stylesheet? =

No. Code Unloader unloads an entire registered CSS file on pages that do not need it. It does not remove individual unused selectors from inside a stylesheet.

= Can I unload plugin CSS and JavaScript without writing code? =

Yes. Open the frontend asset panel, switch off the asset, and choose where the rule should apply. You do not need to add PHP snippets or edit theme files.

= Can unloading an asset break a page? =

Yes. A page can lose styling or functionality if you unload a required dependency. Test each change, preferably on staging. Use `?nowpcu` for a one-request bypass or the kill switch to restore all assets immediately.

= Will my rules survive a cache flush or plugin reactivation? =

Yes. Rules are stored in a custom database table and are not removed when plugin caches are cleared or Code Unloader is reactivated.

= What is the kill switch? =

The kill switch is an emergency recovery option in **Settings > Code Unloader > Settings**. When active, all rules are bypassed and every asset loads normally. Your rules are not deleted and resume when you deactivate the kill switch.

= What does the ?wpcu parameter do? =

Append `?wpcu` to a frontend URL to open the asset panel for logged-in administrators, including on pages where the Admin Toolbar is hidden. The parameter remains while the panel is open and is removed when you close it.

= What does the ?nowpcu parameter do? =

Append `?nowpcu` to a URL to bypass all Code Unloader rules for that request. The page loads as if Code Unloader were not applying any rules. This is useful for testing, debugging, and comparing optimized and unoptimized asset loading.

= Does it support wildcard and regular-expression rules? =

Yes. Choose an exact URL, wildcard pattern, or regular expression when creating a rule. Regular expressions are validated before saving.

= Can it unload inline script and style blocks? =

The Inline Blocks tab detects and lists `<script>` and `<style>` tags printed directly into the page HTML. It helps you identify inline code, but those blocks cannot currently be unloaded from the frontend panel.

= Does Code Unloader replace a caching or minification plugin? =

No. Code Unloader controls whether a registered CSS or JavaScript file loads on a matching page. Caching and minification plugins change how files are stored or delivered. They can be used together.

= What PHP version do I need? =

PHP 8.0 or higher. The plugin will not activate on PHP 7.x.

== Screenshots ==

1. Frontend asset manager showing CSS and JavaScript grouped by plugin, theme, or WordPress Core
2. Create an unloading rule by URL match, device type, and condition
3. View and filter all CSS and JavaScript unloading rules from one screen
4. Organize related asset rules into groups and enable or disable them together
5. Review rule changes in the audit log
6. Use the kill switch to bypass all rules without deleting them

== Changelog ==

= 1.4.11 =
* Added: filter the Rules tab by URL. A new URL/Group switch sits at the top of the rules list; pick a URL to see only the rules stored for that page. The list opens on "All URLs".
* Changed: the rules toolbar now filters by URL or by Group, one at a time, instead of by Group only.
* Changed: tested WordPress compatibility updated to 7.1 and plugin version bumped to 1.4.11.
* Changed: reworded the AI Assets Scanner sidebar card.

= 1.4.10 =
* Fixed: after re-enabling one asset from the frontend panel, later disabled rows no longer inherit duplicate toggle listeners that could open the Disable Asset dialog instead of the re-enable scope chooser.

For the complete release history, see `changelog.txt` in the plugin folder.
