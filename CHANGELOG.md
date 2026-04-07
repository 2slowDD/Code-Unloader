# Changelog

All notable changes to Code Unloader will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.2] - 2026-04-07

### Fixed

* DB migration now runs automatically on plugin update — schema upgrades no longer require manual deactivation/reactivation
* `uniq_rule` database index updated to include `group_id` — the same rule can now correctly exist in multiple groups at the database level (DB version 1.2)
* "View Rules" modal now correctly lists rules for disabled groups — previously returned "No rules in this group." due to the disabled-group filter being applied unconditionally in `get_rules_filtered()`

## [1.4.1] - 2026-03-24

### Fixed

* Cloudflare cache integration corrected — `cloudflare_purge_by_url` and `cloudflare_purge_everything` are filters, not actions; both replaced with the correct `cloudflare_purge_everything_actions` filter mechanism

## [1.4.0] - 2026-03-23

### Added

* "View Rules" button on each group card — opens a modal listing all rules in that group with zebra-stripe styling and pagination (50 per page)

### Changed

* Rule uniqueness constraint now scoped per group — the same asset/URL combination can exist independently in multiple groups

## [1.3.9] - 2026-03-20

### Changed

* Bypass mechanism replaced — appending `?nowpcu` to any URL now disables all Code Unloader rules for that request, following the same convention as `?nowprocket` (WP Rocket) and `?ao_noptimize=1` (Autoptimize)

## [1.3.8] - 2026-03-20

### Removed

* `CU_SCANNER_ACTIVE` constant bypass (superseded by `?nowpcu`)

## [1.3.7] - 2026-03-20

### Fixed

* All plugin-specific PHP constants, option names, transient keys, object cache keys, screen option names, nonce strings, and JS globals renamed from the short `cu_` prefix to `cdunloader_` to comply with WordPress.org prefix length requirements (minimum 4 characters)
* Third-party global variable `$nginx_helper` in CachePurger suppressed with phpcs inline comment

## [1.3.6] - 2026-03-16

### Added

* Speed Analyzer CTA box added to admin sidebar with icon and link
* Ratings & Reviews and Get Support boxes added to admin sidebar
* Two-column layout for admin screen (main content + sidebar)

### Fixed

* Inline `<script>` for `CU_DATA` replaced with `wp_add_inline_script()` per WP.org guidelines
* Plugins-page delete confirmation JS moved to enqueued file (`delete-confirm.js`), removing inline `<script>`
* Contributors field updated to correct WordPress.org username

## [1.3.5] - 2026-03-16

### Changed

* Frontend panel width reduced from 800px to 750px

### Fixed

* Removed unused jQuery dependency from admin script enqueue

## [1.3.4] - 2026-03-05

### Changed

* Updated plugin icon

## [1.3.3] - 2026-03-05

### Added

* Empty-state guidance message shown below the summary bar when no rules exist yet

## [1.3.2] - 2026-03-04

### Fixed

* Disabled files link hover colour on light theme — was nearly invisible due to opacity on dark-red text over light background; now renders as a darker red

## [1.3.1] - 2026-03-03

### Fixed

* Stats bar ("Disabled on this URL") now updates instantly on every toggle — no refresh needed
* Duplicate import success message resolved via PRG redirect (Post-Redirect-Get pattern)
* Warning banner icon, Dismiss button and "Don't show again" link are now vertically centered

### Added

* "Re-enable all" button added to the stats bar, re-enables all disabled assets on the page at once
* Disabled file count in stats bar is now a link that scrolls to the first disabled asset row

### Changed

* "Reduced by:" text updated to "Unloaded from this URL:"
* Warning banner body text is now fully bold for better readability

## [1.3.0] - 2026-03-02

### Added

* Disable Asset dialog now has a "＋ Create new group" option — creates the group on save and syncs it to the Groups panel
* Disabled files summary bar on Assets tab: "Disabled on this URL: X files · Reduced by: Y KB"
* Filter by Group dropdown on the Rules admin tab (including Ungrouped filter)
* Delete All Rules button in the Rules summary bar with confirmation popup

### Fixed

* Plugin URI updated to <https://wpservice.pro/>
* Selected rules are deselected after bulk group assignment
* Assets panel now syncs rule/group data when user returns to the browser tab (visibilitychange)
* Duplicate import confirmation message resolved — notices rendered inline in Settings tab only
* Group filter in Rules admin now supports filtering by specific group or Ungrouped

### Changed

* "Delete" button on group cards renamed to "Delete Group"

## [1.2.5] - 2026-03-01

### Fixed

* Export now includes ALL rules including those in disabled groups (previously get\_rules\_filtered silently excluded them)
* Export strips the runtime-only group\_enabled JOIN column so the JSON is clean
* Import now restores groups first, builds an old-ID-to-new-ID map, then remaps each rule's group\_id before inserting
* Import matches existing groups by name to avoid duplicates; rules stay linked to the correct group
* Import preserves group enabled/disabled state

### Improved

* Import success message now reports rules imported, groups created, and existing groups matched

## [1.2.4] - 2026-03-01

### Changed

* Source header row (light) — gradient from subtle purple to deep red tint
* Source header row (dark) — gradient from #f0f0f0 to deep red tint; chevron now white
* Warning banner — stronger red gradient applied to both themes

## [1.2.3] - 2026-03-01

### Changed

* Toggle track for unloaded (off) assets changed to red (#cc1818) in both light and dark themes

### Improved

* Group-disabled assets now show both the blue "Group: X" badge and a red "Disabled (match\_type)" badge, consistent with non-grouped disabled assets

## [1.2.2] - 2026-02-28

### Changed

* Source group header rows now have a distinct background to visually separate plugins
* Light theme — header row background: #5500cc52 (semi-transparent purple); count/size/action badges: white with dark text
* Dark theme — header row background: #f0f0f0; source label color: #234897; all badges remain white-on-dark for contrast
* All new colour pairs verified against WCAG AA contrast ratios

## [1.2.1] - 2026-02-28

### Fixed

* Disabled-group rules now correctly excluded from panel rule\_map at PHP render time (FrontendPanel)
* Same (int) cast bug found and fixed in InlineBlocker group\_enabled check
* REST get\_rules endpoint page\_url branch now skips disabled-group rules
* Disabling a group now suspends its rules — they disappear from the Rules tab, assets load normally on frontend and in panel
* "N total rules" count and Rules table now exclude suspended (disabled-group) rules
* COUNT query in get\_rules\_filtered now JOINs groups table so the group-enabled filter applies correctly to pagination totals
* Panel (GET /assets) now skips disabled-group rules so assets correctly show as Active when their group is disabled

## [1.2.0] - 2026-02-27

### Fixed

* Disabling a group now correctly stops its rules from being applied — wpdb returns column values as strings, so "0" was truthy and the group-disabled check never fired; fixed with explicit (int) cast

## [1.1.9] - 2026-02-27

### Fixed

* "N total rules" counter now updates instantly after any delete (single, bulk, stale) — no page reload needed
* Disabling a group now immediately stops its rules from being applied on the frontend — root cause was static in-memory rule cache not being cleared when group enabled state changed

## [1.1.8] - 2026-02-26

### Fixed

* "Assign" / "Save" button stays greyed out after first group assignment — button now always re-enables after any outcome
* Group column shows raw ID instead of name when assigning to a newly created group — integer type mismatch corrected
* GET /assets endpoint now keys rules by handle|type (not handle alone) so same-handle JS+CSS rules both survive

### Improved

* Panel now live-syncs rule\_map and groups from the REST API on every open — admin changes are reflected without a page reload

## [1.1.7] - 2026-02-25

### Changed

* Admin layout widened from 1100px to 1200px
* Rules table now defaults to 10 per page (was 20)

### Added

* "Rules per page" screen option (10 / 20 / 50) accessible via Screen Options tab at the top of the admin page

## [1.1.6] - 2026-02-24

### Fixed

* Group column in Rules table now updates instantly after bulk-assign — no page reload needed

## [1.1.5] - 2026-02-23

### Added

* "Group" column in admin Rules table — shows group name as a teal pill, or "—" if ungrouped

### Changed

* Settings icon in panel header replaced with a clean solid SVG gear (matches browser native style)

## [1.1.4] - 2026-02-22

### Fixed

* Plugin zip now correctly extracts to `code-unloader/` folder — resolves all PCP text domain mismatch false positives caused by wrong folder name

### Added

* Settings (gear) icon button in panel header — links directly to the Code Unloader admin screen

## [1.1.3] - 2026-02-21

### Changed

* Disable Asset dialog widened from 560px to 660px for better readability
* Version number now displayed next to plugin title in admin screen header

### Fixed

* Inline Blocks info message no longer references an unbuilt feature

## [1.1.2] - 2026-02-20

### Added

* Version displayed in panel header (plugin version + panel.js/panel.css file versions)
* "Everywhere except here" scope option in the Disable Asset dialog
* Inline Blocks tab now shows informational notice — blocks cannot be unloaded from the panel
* Inline Blocks can be grouped by type (JS / CSS) via a toggle button

### Changed

* Inline Blocks CSS items now styled in blue (matching Assets tab), JS items in amber/yellow

### Fixed

* Removed noisy "[Code Unloader] inline\_blocks: N detected" console log
* "Save Rule" button text now has full contrast in light mode
* "Cancel" button text now visible on hover in dark mode

## [1.1.0] - 2026-02-15

### Fixed

* Panel now persists across page refresh (?wpcu stays in URL)
* Inline Blocks tab now detects and displays inline scripts and styles
* Close button properly strips ?wpcu so refresh after close won't reopen

### Changed

* Version-stamped asset files for easier cache debugging
* Updated readme with PHP 8.0 requirement explanation and inline blocks FAQ

## [1.0.0] - 2025-01-01

### Added

* Per-page JavaScript and CSS asset management
* Exact URL, wildcard pattern, and regex URL matching
* Frontend panel accessible from Admin Toolbar and via `?wpcu` parameter
* Assets grouped by plugin, theme, or WordPress Core
* Global admin screen with Rules, Groups, Audit Log, and Settings tabs
* One-click kill switch for emergency asset restoration
* Conditional rules (logged-in users, WooCommerce pages, shortcodes, post types)
* Device-type targeting (desktop-only, mobile-only)
* Inline script/style blocking for unregistered handles
* Rule groups for batch management
* Full audit log
* JSON import/export
* Cache purge integration (WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache)
