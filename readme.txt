=== Code Unloader ===
Contributors: dalibord
Tags: performance, assets, scripts, styles, dequeue
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.4.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-page JavaScript & CSS asset management. Surgically dequeue scripts and styles on any page using exact, wildcard, or regex URL rules.

== Description ==
[youtube https://youtu.be/abCdOEl1cxg]

Official plugin homepage:
[https://wpservice.pro/our-products/code-unloader/](https://wpservice.pro/our-products/code-unloader/)

Code Unloader gives site administrators surgical control over which JavaScript and CSS files are loaded on each individual page or post.

**Key Features:**

* Disable any registered JS or CSS file on any page or post
* Exact URL, wildcard pattern (/shop/*), and full regex matching
* Rules survive cache flushes and plugin reactivations
* Assets grouped by plugin, theme, or WordPress Core in the panel
* Per-page frontend panel accessible from the Admin Toolbar
* Access panel on any page via `?wpcu` URL parameter
* Global admin screen listing all rules across the site
* One-click kill switch to instantly restore all assets sitewide
* Bypass all rules for a single request via `?nowpcu` URL parameter
* Conditional rules (logged-in users, WooCommerce pages, shortcodes, post types)
* Device-type rules (desktop-only or mobile-only)
* Inline script/style blocking for assets without registered handles
* Inline block detection — see every inline `<script>` and `<style>` on the page
* Rule groups for managing sets of rules as a unit
* Full audit log of all changes
* JSON import/export
* Zero performance overhead on pages with no matching rules

**Compatible with:** WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, WooCommerce, Elementor, Divi, WP Bakery, basically everything WP related.

**Requirements:** PHP 8.0 or higher is required. The plugin uses modern PHP features (union types, match expressions, named functions) that are not available in PHP 7.x.

**Note:** It's recommended to test changes on a staging environment before applying them to a live site. Unloading the wrong assets can break your site's appearance or functionality.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/code-unloader`
2. Activate the plugin through the **Plugins** screen in WordPress
3. Visit any page while logged in as an admin and click **⚡ Assets** in the Admin Toolbar
4. Toggle off any asset — a rule creation dialog will appear

== Frequently Asked Questions ==

= What PHP version do I need? =
PHP 8.0 or higher. The plugin will not activate on PHP 7.x.

= Will my rules survive a cache flush? =
Yes. Rules are stored in a custom database table and are not affected by caching plugin cache clears.

= What is the kill switch? =
The kill switch is a one-click emergency recovery button in **Settings > Code Unloader > Settings**. When active, all rules are bypassed and every asset loads normally. Your rules are not deleted — they resume when you deactivate the kill switch.

= What does the ?wpcu parameter do? =
Appending `?wpcu` to any frontend URL will automatically open the asset panel for logged-in administrators, even on pages where the Admin Toolbar is hidden. The parameter stays in the URL while the panel is open and is removed when you close it.

= What does the ?nowpcu parameter do? =
Appending `?nowpcu` to any frontend URL disables all Code Unloader rules for that single request — the page loads exactly as if the plugin were not active. This follows the same convention as `?nowprocket` (WP Rocket) and `?ao_noptimize=1` (Autoptimize). Useful for testing, debugging, or performance scanning tools that need to measure the raw unoptimized asset baseline.

= Does it support regex? =
Yes. When creating a rule, choose **Regular Expression** as the match type. Patterns are validated before saving, and a regex warning is shown to help you keep patterns specific.

= What are inline blocks? =
Inline blocks are `<script>` and `<style>` tags that are printed directly into the page HTML rather than being registered through WordPress's enqueue system. The Inline Blocks tab in the panel detects and lists these blocks so you can identify them.

== Screenshots ==

1. Frontend panel showing assets grouped by source
2. Rule creation dialog with match type, device, and condition options
3. Global admin screen — Rules tab
4. Admin screen — Groups tab
5. Admin screen — Audit Log tab
6. Admin screen — Settings tab with kill switch

== Changelog ==

= 1.4.5 =
* Fixed: bulk rule deletion ("Delete All Active Rules" / multi-select bulk delete) now purges 3rd-party page cache (WP Rocket, LiteSpeed, SG Optimizer, FlyingPress, Hummingbird, Autoptimize, Breeze, Nginx Helper, Cloudflare, etc.). Previously these paths only cleared the WP object cache, so cached HTML kept serving until the cache TTL expired or the plugin was deactivated/reactivated — sometimes leaving stale console errors from already-stripped inline localizes.
* Fixed: enabling/disabling a group via the Groups tab now purges 3rd-party page cache. Same root cause as above; toggling a group's enabled flag changes which rules apply at runtime.
* Fixed: deleting a single group (`DELETE /groups/{id}`) and deleting all groups now purges 3rd-party page cache.
* Changed: enabled groups now float to the top of the Groups tab. Active groups are visible at a glance; alphabetical order is preserved within each enabled/disabled bucket.
* Internal: `phpcs:enable` scope corrected in `delete_all_groups()` so the `WordPress.DB.DirectDatabaseQuery.*` suppression doesn't leak past the intended block.

= 1.4.4 =
* Added: "Delete All Groups" button on the Groups tab. Wipes all groups and their snapshots. Active rules survive and become ungrouped.
* Added: live sync between frontend CU Panel and admin Rules tab — rule changes made from the panel now update the Rules tab list + total count automatically (same-browser only, via BroadcastChannel with a storage-event fallback).

= 1.4.3 =
* Fixed: `POST /rules` returning 500 (Internal Server Error) when creating rules whose 6-column scope (url_pattern, match_type, asset_handle, asset_type, device_type, group_id) already existed with different condition settings — the in-PHP duplicate check was stricter than the DB UNIQUE KEY, allowing the insert to fall through to a duplicate-key violation
* Fixed: AI Assets Scanner push silently dropping rules — same root cause as above; pushed rules that shared the 6-column scope but differed in condition fields were rejected by the DB after passing the PHP dedup check
* Fixed: cu-panel "deactivate second asset" 500 error — same root cause
* Fixed: page-scoped asset re-enable (`POST /rules/enable` with `scope: page`) threw a TypeError — `PatternMatcher::match()` was called with the pre-1.5 signature
* Internal: `RuleRepository::find_duplicate()` now matches the DB UNIQUE KEY exactly (6-column identity). Conditions are attributes of a rule, not part of its identity — one active rule per (url_pattern, match_type, asset_handle, asset_type, device_type, group_id) scope
* Internal: `cu_log.action` ENUM in fresh `CREATE TABLE` now includes `group_activate` and `group_deactivate` (matches the migration ALTER)
* Internal: added defensive auto-heal for legacy `identity_key` schema drift on cu_rules (pre-release dev artifact) — drops the orphan column + misaligned UNIQUE KEY and re-adds the correct 6-column composite key
* Internal: added companion auto-heal for legacy `snapshot_key` schema drift on cu_group_items — same pattern, different table; collapsed group snapshots to 1 per group, which broke group enable/disable cascade on rules
* Both heals safety-gated: bail without data loss if pre-existing rows would violate the corrected key
* Schema version bumped to 1.5.2 to trigger both heals on sites already at DB 1.5

= 1.5.0 =
* Added group snapshot library (cu_group_items) — group membership is now independent of active rules
* Added scoped panel re-enable: "On this page" and "Globally" buttons
* Added REST endpoints: POST /rules/enable, GET /groups/{id}/items
* Group counts now reflect saved snapshots, not active rules
* Automatic non-destructive migration from 1.4.x

= 1.4.2 =
* Fixed: DB migration now runs automatically on plugin update — schema upgrades no longer require manual deactivation/reactivation
* Fixed: `uniq_rule` database index updated to include `group_id` — the same rule can now correctly exist in multiple groups at the database level (DB version 1.2)
* Fixed: "View Rules" modal now correctly lists rules for disabled groups — previously returned "No rules in this group." due to the disabled-group filter being applied unconditionally
* Fixed: Duplicate rules within the same group or ungrouped Rules tab are now silently blocked — creating an identical rule (same URL, handle, type, device, and condition) in the same scope skips the insert and returns the existing rule

= 1.4.1 =
* Fixed: Cloudflare cache integration corrected — cloudflare_purge_by_url and cloudflare_purge_everything are filters, not actions; both replaced with the correct cloudflare_purge_everything_actions filter mechanism

= 1.4.0 =
* New: "View Rules" button on each group card — opens a modal listing all rules in that group with zebra-stripe styling
* Changed: Rule uniqueness constraint now scoped per group — the same asset/URL combination can exist independently in multiple groups

= 1.3.9 =
* Changed: Bypass mechanism replaced — appending ?nowpcu to any URL now disables all Code Unloader rules for that request, following the same convention as ?nowprocket (WP Rocket) and ?ao_noptimize=1 (Autoptimize)

= 1.3.8 =
* Removed: CU_SCANNER_ACTIVE constant bypass (superseded by ?nowpcu)

= 1.3.7 =
* Fixed: All plugin-specific PHP constants, option names, transient keys, cache keys, and JS globals renamed from the short "cu_" prefix (2 chars) to "cdunloader_" to comply with WordPress.org prefix length requirements (minimum 4 characters)
* Fixed: Third-party global variable $nginx_helper in CachePurger now suppressed with phpcs inline comment

= 1.3.6 =
* New: Speed Analyzer CTA box added to admin sidebar with icon and link


= 1.3.5 =
* Changed: Frontend panel width reduced from 800px to 750px
* Fixed: Removed unused jQuery dependency from admin script enqueue


= 1.3.4 =
* Updated plugin icon
* Fixed: Inline <script> for CU_DATA replaced with wp_add_inline_script() per WP.org guidelines
* Fixed: Plugins-page delete confirmation JS moved to enqueued file (delete-confirm.js), removing inline <script>
* Fixed: Contributors field updated to correct WordPress.org username (dalibord)
* New: Ratings & Reviews / Get Support sidebar added to admin screen


= 1.3.3 =
* New: Empty-state guidance message shown below the summary bar when no rules exist yet


= 1.3.2 =
* Fixed: Disabled files link hover colour on light theme — was nearly invisible due to opacity on dark-red text over light background; now renders as a darker red


= 1.3.1 =
* Fixed: Stats bar ("Disabled on this URL") now updates instantly on every toggle — no refresh needed
* New: "Re-enable all" button added to the stats bar, re-enables all disabled assets on the page at once
* Fixed: Duplicate import success message resolved via PRG redirect (Post-Redirect-Get pattern)
* New: Disabled file count in stats bar is now a link that scrolls to the first disabled asset row
* Changed: "Reduced by:" text updated to "Unloaded from this URL:"
* Changed: Warning banner body text is now fully bold for better readability
* Fixed: Warning banner icon, Dismiss button and "Don't show again" link are now vertically centered


= 1.3.0 =
* New: Disable Asset dialog now has a "＋ Create new group" option — creates the group on save and syncs it to the Groups panel
* New: Disabled files summary bar on Assets tab: "Disabled on this URL: X files · Reduced by: Y KB"
* New: Filter by Group dropdown on the Rules admin tab (including Ungrouped filter)
* New: Delete All Rules button in the Rules summary bar with confirmation popup
* Fixed: Plugin URI updated to https://wpservice.pro/
* Fixed: Selected rules are deselected after bulk group assignment
* Fixed: Assets panel now syncs rule/group data when user returns to the browser tab (visibilitychange)
* Fixed: Duplicate import confirmation message resolved — notices rendered inline in Settings tab only
* Fixed: Group filter in Rules admin now supports filtering by specific group or Ungrouped
* Changed: "Delete" button on group cards renamed to "Delete Group"


= 1.2.5 =
* Fixed: Export now includes ALL rules including those in disabled groups (previously get_rules_filtered silently excluded them)
* Fixed: Export strips the runtime-only group_enabled JOIN column so the JSON is clean
* Fixed: Import now restores groups first, builds an old-ID-to-new-ID map, then remaps each rule's group_id before inserting
* Fixed: Import matches existing groups by name to avoid duplicates; rules stay linked to the correct group
* Fixed: Import preserves group enabled/disabled state
* Improved: Import success message now reports rules imported, groups created, and existing groups matched


= 1.2.4 =
* Style: Source header row (light) — gradient from subtle purple to deep red tint
* Style: Source header row (dark) — gradient from #f0f0f0 to deep red tint; chevron now white
* Style: Warning banner — stronger red gradient applied to both themes


= 1.2.3 =
* Style: Toggle track for unloaded (off) assets changed to red (#cc1818) in both light and dark themes
* Improved: Group-disabled assets now show both the blue "Group: X" badge and a red "Disabled (match_type)" badge, consistent with non-grouped disabled assets


= 1.2.2 =
* Style: Source group header rows now have a distinct background to visually separate plugins
* Style: Light theme — header row background: #5500cc52 (semi-transparent purple); count/size/action badges: white with dark text
* Style: Dark theme — header row background: #f0f0f0; source label color: #234897; all badges remain white-on-dark for contrast
* All new colour pairs verified against WCAG AA contrast ratios


= 1.2.1 =
* Fixed: Disabled-group rules now correctly excluded from panel rule_map at PHP render time (FrontendPanel)
* Fixed: Same (int) cast bug found and fixed in InlineBlocker group_enabled check
* Fixed: REST get_rules endpoint page_url branch now skips disabled-group rules
* Fixed: Disabling a group now suspends its rules — they disappear from the Rules tab, assets load normally on frontend and in panel
* Fixed: "N total rules" count and Rules table now exclude suspended (disabled-group) rules
* Fixed: COUNT query in get_rules_filtered now JOINs groups table so the group-enabled filter applies correctly to pagination totals
* Fixed: Panel (GET /assets) now skips disabled-group rules so assets correctly show as Active when their group is disabled


= 1.2.0 =
* Fixed: Disabling a group now correctly stops its rules from being applied — wpdb returns column values as strings, so "0" was truthy and the group-disabled check never fired; fixed with explicit (int) cast


= 1.1.9 =
* Fixed: "N total rules" counter now updates instantly after any delete (single, bulk, stale) — no page reload needed
* Fixed: Disabling a group now immediately stops its rules from being applied on the frontend — root cause was static in-memory rule cache not being cleared when group enabled state changed


= 1.1.8 =
* Fixed: "Assign" / "Save" button stays greyed out after first group assignment — button now always re-enables after any outcome
* Fixed: Group column shows raw ID instead of name when assigning to a newly created group — integer type mismatch corrected
* Improved: Panel now live-syncs rule_map and groups from the REST API on every open — admin changes are reflected without a page reload
* Fixed: GET /assets endpoint now keys rules by handle|type (not handle alone) so same-handle JS+CSS rules both survive


= 1.1.7 =
* Improved: Admin layout widened from 1100px to 1200px
* Improved: Rules table now defaults to 10 per page (was 20)
* Added: "Rules per page" screen option (10 / 20 / 50) accessible via Screen Options tab at the top of the admin page


= 1.1.6 =
* Fixed: Group column in Rules table now updates instantly after bulk-assign — no page reload needed


= 1.1.5 =
* Added: "Group" column in admin Rules table — shows group name as a teal pill, or "—" if ungrouped
* Improved: Settings icon in panel header replaced with a clean solid SVG gear (matches browser native style)


= 1.1.4 =
* Fixed: Plugin zip now correctly extracts to `code-unloader/` folder — resolves all PCP text domain mismatch false positives caused by wrong folder name
* Added: Settings (⚙) icon button in panel header — links directly to the Code Unloader admin screen

= 1.1.3 =
* Improved: Disable Asset dialog widened from 560px to 660px for better readability
* Improved: Version number now displayed next to plugin title in admin screen header
* Fixed: Inline Blocks info message no longer references an unbuilt feature

= 1.1.2 =
* Added: Version displayed in panel header (plugin version + panel.js/panel.css file versions)
* Added: "Everywhere except here" scope option in the Disable Asset dialog
* Improved: Inline Blocks tab now shows informational notice — blocks cannot be unloaded from the panel
* Improved: Inline Blocks CSS items now styled in blue (matching Assets tab), JS items in amber/yellow
* Improved: Inline Blocks can be grouped by type (JS / CSS) via a toggle button
* Fixed: Removed noisy "[Code Unloader] inline_blocks: N detected" console log
* Fixed: "Save Rule" button text now has full contrast in light mode
* Fixed: "Cancel" button text now visible on hover in dark mode

= 1.1.0 =
* Fixed: Panel now persists across page refresh (?wpcu stays in URL)
* Fixed: Inline Blocks tab now detects and displays inline scripts and styles
* Fixed: Close button properly strips ?wpcu so refresh after close won't reopen
* Improved: Version-stamped asset files for easier cache debugging
* Improved: Updated readme with PHP 8.0 requirement explanation and inline blocks FAQ

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.4.3 =
Hotfix for 1.5.0. Fixes 500 errors on POST /rules (second asset deactivation, AI Scanner rule import). Includes defensive auto-heal for a legacy `identity_key` schema drift seen on pre-release dev installs. No deactivate/activate required.

= 1.1.0 =
Panel persistence and inline block detection fixes. Bump to v1.1.0 recommended.
