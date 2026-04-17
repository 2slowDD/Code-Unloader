# Changelog

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
