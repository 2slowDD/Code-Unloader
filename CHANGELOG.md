# Changelog

## [1.4.6] — 2026-04-29

Hotfix bundle. PHP-logic + JS only — no schema changes, no deactivate/activate required.

> **Bug 2 dependency:** the AI Assets Scanner → CU Rules-tab live-refresh requires AI Assets Scanner **≥ 1.2.2**. Scanner 1.2.1 ships the same `scanner.js` file size with the same `?ver=` query string, so browsers keep serving the pre-emit cached copy. If you deploy CU 1.4.6 against Scanner ≤ 1.2.1, Bugs 1 and 3 work but Bug 2 stays unfixed in the browser.

### Fixed
- **Re-enabling a grouped rule from the panel made the asset row vanish on refresh.** Panel built `assets[]` from `$wp_scripts->queue` at `wp_enqueue_scripts:999` plus a `was_dequeued` fallback that only fired when an active rule still matched. Source plugins enqueueing at a higher priority than 999 (or via `wp_footer`) were missed by the queue scan and lost the fallback after rule delete. Fix: merge `cu_group_items` snapshots from enabled groups whose URL pattern matches the current URL into `assets[]` in `FrontendPanel::enqueue_panel_assets()`. Snapshot is the user's source-of-truth for "I manage this asset on this URL" and survives rule deletion. New `RuleRepository::get_group_item_snapshots_for_url()` encapsulates the join + URL match + versioned-cache; mutating paths (`create_group_item`, `delete_group_items`, `delete_all_groups`, plus `update_group` on enable-toggle) bump a shared snapshots-version counter to bypass per-URL caches on next read.
- **AI Assets Scanner push left the open CU admin Rules tab stale.** Scanner writes rules server-side via `RuleRepository::create_rule()`, which never reaches the browser. CU's admin Rules tab has been listening for `cu.rule.changed` on `BroadcastChannel('code-unloader')` since 1.4.4, but Scanner never produced the event. Fix: Scanner's `cu-btn-push` success branch now posts `{ type: 'cu.rule.changed', source: 'scanner', action: 'bulk-create' }` on the same channel, with a `localStorage` write/remove fallback for browsers without `BroadcastChannel`. Existing `wireCrossTabSync` listener in `assets/js/admin.js` debounces (250ms), refetches the current admin URL, and replaces `#the-list`, `#cu-total-rules-count`, and `.tablenav` in place. Cross-plugin event (Scanner → CU); CU side unchanged.
- **Panel filename ≠ network-tab filename when an optimizer rewrites the src.** Panel's filename derived from `$wp_scripts->registered[$handle]->src` (developer-registered URL); browser sees the URL after `script_loader_src` / `style_loader_src` filters run (Perfmatters, WP Rocket page-cache mode, hash-versioning, CDN URL-rewriters). Fix: register both filters at `PHP_INT_MAX` in `FrontendPanel::init()` — only when `?wpcu` is present, mirroring the existing head-buffer-scan gating — capture each post-rewrite URL into `self::$rewritten_urls`, then emit a tiny inline-script patch at `wp_footer:PHP_INT_MAX` (after `wp_print_footer_scripts:20` has flushed every footer script tag through the filter) that mutates `window.CDUNLOADER_DATA.assets[i].rewritten_src` on the live array reference. `panel.js` `assetRow()` renders a "→ \<filename\>" line under the registered filename when they differ, italic via the new `.cu-asset-src--rewritten` class (no opacity reduction — keeps WCAG AA contrast). Out of scope: HTML-output-buffering optimizers (Autoptimize, WP-Optimize combine, FlyingPress static-bundling) that rewrite at `template_redirect` time without hooking `script_loader_src` — separate feature, would need an output-buffer scan.

### Changed
- **Panel search filter now covers every visible identifier on a row.** Previously only `handle` and full `src`. Now matches `handle`, full `src`, full `rewritten_src`, `source_label`, and extracted filenames from both `src` and `rewritten_src` — joined into a single per-row haystack, lower-cased once per query. Placeholder text updated from "Filter by handle or filename…" to "Filter by handle, filename, source, or URL…" so the new coverage is discoverable.

### Internal
- Consolidated `FrontendPanel.php:247` panel-version HTML comment from `<!-- Code Unloader Panel v{VERSION} | panel.js v9 | panel.css v9 -->` to `<!-- Code Unloader Panel v{VERSION} -->`. The hardcoded `v9` literals had drifted from the actual `panel.js v10` JSDoc header since 1.4.5. `CDUNLOADER_VERSION` is sufficient build identifier; per-file change tracking continues to live in per-file headers.
- `panel.js` JSDoc header bumped 10 → 11 with v11 changelog block listing the Bug 1 / Bug 3 / search-coverage line items.
- `CDUNLOADER_VERSION` 1.4.5 → 1.4.6. `CDUNLOADER_DB_VERSION` unchanged at 1.5.2 — no schema change.
- Filter priorities: both `script_loader_src` / `style_loader_src` AND the `wp_footer` emit action use `PHP_INT_MAX` for symmetry. A plugin scheduling its own callbacks at the integer cap would still beat us — defensible, accepted.
- Snapshot-merge fallback handles deactivated-plugin handles: when `$wp_scripts->registered[$handle]` is null, the row is still added with empty `src`/`deps` and `source_label` falls back to the snapshot's stored `source_label` column. Prevents a deactivated plugin's snapshots from collapsing into "Unknown / External".
- Snapshot two-group tie-break: `INNER JOIN ... ORDER BY gi.group_id ASC, gi.id ASC`. Lowest-numbered (oldest) group wins reproducibly.
- XSS safety in `emit_rewritten_urls()` documented inline: `wp_json_encode` default-escapes `/` as `\/`, neutralizing `</script>` injection in URL values. Comment warns against adding `JSON_UNESCAPED_SLASHES` (which would silently reintroduce the vector).

---

## [1.4.5] — 2026-04-26

Hotfix. PHP-logic only — no schema changes, no deactivate/activate required.

### Fixed
- **Bulk rule deletion silently leaves 3rd-party page cache stale.** `RuleRepository::delete_rules( array $ids )` and `RuleRepository::delete_all_rules()` previously called only `invalidate_caches()` (WP object cache), so cached HTML kept serving stripped inline localizes from cache plugins (WP Rocket / LiteSpeed / SG Optimizer / FlyingPress / Hummingbird / Autoptimize / Breeze / Nginx Helper / Cloudflare) until the cache TTL expired or the plugin was deactivated/reactivated — which only worked because WP's plugin-lifecycle hooks happen to trigger optimizer auto-purges as a side effect. Both bulk paths now call `CachePurger::purge_all()` after a successful delete (gated on `$deleted > 0`).
- **Group enable/disable toggle leaves 3rd-party page cache stale.** `RuleRepository::update_group()` now calls `CachePurger::purge_all()` when the `enabled` flag flips, on top of the existing `activate_group_items` / `deactivate_group_items` calls. Editing `name` / `description` does not change which rules apply at runtime, so those edits do not trigger a purge.
- **Group deletion (single + bulk) leaves 3rd-party page cache stale.** `RuleRepository::delete_group( int $id )` and `RuleRepository::delete_all_groups()` now call `CachePurger::purge_all()`. Single-group delete is gated on `$result`; the all-groups path always purges because the caller already exited early when zero groups exist.

### Changed
- **Enabled groups float to the top of the Groups tab.** `RuleRepository::get_all_groups()` now sorts by `g.enabled DESC, g.name` so the active set is always visible at a glance — previously buried behind alphabetical-by-name ordering when many disabled groups (e.g. dated "Previously active rules" archives) were present. Alphabetical order is preserved within each enabled/disabled bucket. Single SQL `ORDER BY` change; client `forEach` in [`admin.js:149`](assets/js/admin.js#L149) renders in received order, no JS changes needed.

### Added
- **Group cards display the creation date.** `render_groups_tab()` now shows a small, dimmed "Created, April 23 2026" badge beside the group title, sourced from `cu_groups.created_at` via `get_date_from_gmt()` so it's rendered in the site's timezone. The badge is suppressed when the group name already contains the same formatted date (avoids duplication on auto-created "Previously active rules April 23 2026" archives pushed by AI Assets Scanner).

### Internal
- `phpcs:enable` scope corrected in `delete_all_groups()` so the `WordPress.DB.DirectDatabaseQuery.*` suppression doesn't leak past the intended block — picked up via wp-compliance Rule 20 placement-mechanics audit.
- Single-rule paths (`delete_rule`, `delete_active_rules_by_scope`) already called `CachePurger::purge_for_rule()` per affected URL and are unchanged.
- Purge is fired from top-level user-triggered methods only — not from `activate_group_items` / `deactivate_group_items` — to avoid double-purging when `delete_group` calls those helpers during teardown.

---

## [1.4.4] — 2026-04-18

### Added
- **Delete All Groups** button on the Groups tab. Wipes `cu_groups` and `cu_group_items` in one click; active rules in `cu_rules` are preserved (their `group_id` / `group_item_id` are nulled). Mirrors the existing Delete All Rules pattern — single `confirm()` prompt, `manage_options` gated via `check_permission`.
- **Cross-tab live sync (Rules tab only)**. When a rule is created/enabled/disabled from the frontend CU panel, the admin Rules tab refreshes its list table and total-count in place — no manual reload. Implemented as `assets/js/cu-bus.js` (BroadcastChannel + `storage`-event fallback) + emit points in `panel.js` + listener in `admin.js`. Scoped to `tab=rules` so Groups / Log / Settings tabs are unaffected. Same-browser-same-origin only; no server-side plumbing.

### Internal
- New REST endpoint: `DELETE /groups/delete-all` → `RuleRepository::delete_all_groups()`.
- Script enqueue order: `cu-bus` now loads before `cu-panel` (frontend) and `cu-admin` (admin) as an explicit dependency.
- `CDUNLOADER_VERSION` bumped `1.4.3` → `1.4.4` so the `?ver=` cache-bust query string refreshes the JS files in browsers holding old copies.
- Added `README.md` at the plugin root with project badges (CI, Claude Code Skill, Codex Skill, License, Version). End-user documentation continues to live in `readme.txt` (WordPress.org format).

---

## [1.4.3] — 2026-04-18

Hotfix over 1.5.0. PHP-logic only — no schema changes, no deactivate/activate required.

### Fixed
- **`POST /rules` 500 (Internal Server Error)** when creating a rule whose 6-column scope (`url_pattern`, `match_type`, `asset_handle`, `asset_type`, `device_type`, `group_id`) already existed with different condition settings. `RuleRepository::find_duplicate()` was checking 9 columns (including the three condition fields) while the DB `UNIQUE KEY uniq_rule` on `cu_rules` only covers 6 columns — so the PHP dedup would pass but the `$wpdb->insert` would then collide with the DB unique constraint and return `WP_Error`, which the REST controller mapped to 500.
- **AI Assets Scanner push silently dropping rules** ("6 safe, 40 aggressive generated → 0 safe, 1 aggressive added"). Same root cause as above: pushed rules sharing the 6-column scope but differing in condition fields were rejected by the DB after passing the PHP dedup check.
- **cu-panel: deactivating a second asset returned 500.** Same root cause.
- **`RuleRepository::delete_active_rules_by_scope` (`scope: page`)** was calling `PatternMatcher::match($page_url, $pattern, $match_type)` with the old 3-argument signature — the method now takes `(object $rule, string $url)`. The mismatch would throw a TypeError on any page-scoped re-enable via `POST /rules/enable`.

### Changed
- `RuleRepository::find_duplicate()` now matches the DB `UNIQUE KEY uniq_rule` exactly (6-column identity: `url_pattern`, `match_type`, `asset_handle`, `asset_type`, `device_type`, `group_id`). Conditions are treated as attributes of a rule, not part of its identity — one active rule per scope, per spec.
- `Installer::create_tables()` `cu_log.action` ENUM (for new installs) now includes `group_activate` and `group_deactivate` to match the migration-expanded ENUM that existing installs receive via `ALTER TABLE … MODIFY COLUMN`.
- `phpcs:ignore` on the ENUM `ALTER TABLE` widened to a `phpcs:disable`/`phpcs:enable` pair with an explicit justification comment — per wp-compliance rule 20 (DDL false-positives inside version-gated migrations).

### Schema heal
- **New**: defensive repair in `Installer::maybe_upgrade()` for a legacy `identity_key` schema drift on `cu_rules` observed on pre-release dev installs. Signature: `cu_rules.identity_key CHAR(64) NOT NULL DEFAULT ''` + single-column `UNIQUE KEY uniq_rule (identity_key)`. Because no plugin code populates `identity_key`, every INSERT writes `''` and the second INSERT collides with the first. The heal drops the bad index, drops the orphan column, and re-adds the correct 6-column `uniq_rule`. Safety-gated by a duplicate-groups check — if pre-existing rows would violate the corrected key, the heal bails and stores a `cdunloader_identity_key_heal_blocked` transient instead of destroying data.
- **New**: companion repair for a legacy `snapshot_key` schema drift on `cu_group_items` (same pre-release artifact, different table). Signature: `cu_group_items.snapshot_key CHAR(64) NOT NULL` + `UNIQUE KEY uniq_group_item (group_id, snapshot_key)`. Because no plugin code populates `snapshot_key`, only the first snapshot per group is accepted; all subsequent snapshots collide on `(group_id, '')`. Visible symptom: Groups tab shows 1 item per group regardless of true rule count, and active rules pushed into a group end up with `NULL group_item_id` — so `deactivate_group_items()` can't find them to delete when the group is disabled. The heal drops the bad index, drops the orphan column, and re-adds the correct 9-column `uniq_group_item`. Safety-gated by a duplicate-groups check storing `cdunloader_snapshot_key_heal_blocked` on conflict.
- `CDUNLOADER_DB_VERSION` bumped `1.5` → `1.5.2` to trigger both heal blocks on sites already at DB version 1.5.

### Migration
- Automatic on `plugins_loaded` via the standard `Installer::maybe_upgrade()` path. Correctly-migrated sites see a no-op (two cheap `information_schema` probes).

---

## [1.5.0] — 2026-04-17

### Added
- **Group snapshots (`cu_group_items`)** — group membership is now stored independently of active rules. Deleting or re-enabling an active rule no longer removes it from its group.
- **`group_item_id` column on `cu_rules`** — active rules that originated from a group carry a reference back to their snapshot.
- `POST /rules/enable` REST endpoint — enables a disabled asset on the current page only (`scope: page`) or globally (`scope: global`) without touching group snapshots.
- `GET /groups/{id}/items` REST endpoint — returns saved group snapshots for use in the Groups tab View Rules modal.
- Inline split buttons on the CU panel — clicking the toggle on a disabled asset now shows **On this page** / **Globally** buttons instead of silently deleting a single rule.
- `activate_group_items` / `deactivate_group_items` — enabling or disabling a group materialises / removes active rules from the snapshot library.
- `delete_active_rules_by_scope` — scoped rule removal used by the new panel enable flow.
- Export now includes `group_items`; import re-links snapshots to remapped groups before importing active rules.

### Changed
- **Group count** on the Groups tab now reflects saved snapshots, not active rules — disabled groups show their full rule library.
- **Delete Rule / Bulk Delete / Delete All** now remove only active rules; saved group snapshots are never affected.
- **Delete Group** now properly removes active rules and snapshots before deleting the group row.
- **View Rules modal** in the Groups tab reads saved snapshots from `/groups/{id}/items` instead of the active-rules endpoint.
- Schema version bumped to `1.5`; plugin version bumped to `1.5.0`.

### Migration
- Automatic on `plugins_loaded`. Existing grouped rules are snapshotted into `cu_group_items` and linked via `group_item_id`. Non-destructive and idempotent.
