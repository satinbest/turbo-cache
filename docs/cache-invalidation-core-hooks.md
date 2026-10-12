# Phase 5B — WordPress Core Invalidation Hooks

## Overview

Phase 5B connects the WordPress Core content mutation lifecycle to the Turbo Cache targeted invalidation engine. The core module [inc/cache-invalidation.php](file:///d:/turbo-cache/inc/cache-invalidation.php) was introduced in Phase 5B (commit `3651faa`), establishing dedicated procedural functions for targeted invalidation, path canonicalization, request-local deduplication, and lifecycle integrations.

The goal of this phase is strictly scoped to WordPress Core standard content mutations (posts and pages) while preserving upstream WP Super Cache compatibility, avoiding premature architectural additions, and ensuring that unrelated cached content remains untouched.

---

## 1. Connected WordPress Hooks & Lifecycle Registrations

Turbo Cache hooks are registered in [wp-cache-phase2.php](file:///d:/turbo-cache/wp-cache-phase2.php) (`wpsc_register_post_hooks`) and connect into [inc/cache-invalidation.php](file:///d:/turbo-cache/inc/cache-invalidation.php):

| Hook / Trigger | Callback / Entrypoint | Priority | Trigger Timing & Condition | Invalidation Targets |
| :--- | :--- | :--- | :--- | :--- |
| `before_delete_post` | `turbo_cache_before_delete_post($post_id, $post)` | 0 | Before core removes metadata or alters post data. | Original public URL of previously published posts deleted directly or from Trash. |
| `clean_post_cache` | `wp_cache_post_edit($post_id)` | 10 | Core post cache flush (updates, deletes, status transitions). | Canonical Post/Page URL + Homepage (if post affects front page). |
| `transition_post_status` | `wpsc_post_transition($new_status, $old_status, $post)` | 10 | Status transition (e.g. `draft -> publish`, `publish -> trash`, `publish -> draft`). | Canonical Post/Page URL + Homepage via `turbo_cache_post_status_transition()`. |
| `post_updated` | `turbo_cache_post_updated($post_id, $post_after, $post_before)` | 10 | Post update before/after comparison. | Previous canonical URL (slug/parent hierarchy changes) and new canonical URL. |
| `set_object_terms` | `turbo_cache_terms_changed($object_id, ...)` | 10 | Object taxonomy relationships updated. | Old and new post URLs when permalinks contain `%category%`. |
| `publish_post` | `wp_cache_post_edit($post_id)` | 0 | Post publication. | Canonical Post URL via `turbo_cache_invalidate_post()` + upstream archive cleanup. |
| `edit_post` | `wp_cache_post_change($post_id)` | 0 | Direct post edit. | Clears legacy query cache `.meta` files and archives. |
| `delete_post` | `wp_cache_post_edit($post_id)` | 0 | Post deletion. | Legacy handler; skips non-public posts. |
| `wp_trash_post` | `wp_cache_post_edit($post_id)` | 0 | Post moved to trash. | Canonical Post URL via `turbo_cache_invalidate_post()`. |
| `wpsc_delete_url_cache` | `wpsc_delete_url_cache($url)` | — | Helper called when a specific URL cache must be purged. | Preserves legacy staging if `$cache_rebuild_files` is set; otherwise calls `turbo_cache_purge_url($url)`. |

---

## 2. Invalidation Flow & Target Resolution

The invalidation path follows a strict procedural pipeline:

```text
WordPress Mutation (e.g., save_post / clean_post_cache / transition_post_status / post_updated / before_delete_post / set_object_terms)
    ↓
Target Identification:
    - Current Canonical URL: turbo_cache_get_post_canonical_url( $post_id )
    - Previous Canonical URL (on post_updated or set_object_terms): turbo_cache_resolve_post_object_url()
    - Trashed Post Original URL: reconstructed slug (minus __trashed) via sample permalink clone
    - Homepage check: turbo_cache_post_affects_homepage( $post_id )
    ↓
Request-Local Deduplication:
    - turbo_cache_deduplicate_purge( $canonical_target )
    ↓ (if not already purged during request)
Targeted Purge:
    - Post URL Storage Purge: turbo_cache_purge_url( $url )
    - Homepage Purge (if applicable): turbo_cache_invalidate_homepage()
    ↓
Disk Cleanup:
    - turbo_cache_purge_storage_dir( $target_dir, $delete_dir, $success )
      Removes index.html, index-https.html, .gz companions, .needs-rebuild, .tmp files,
      and paginated subdirectories (page/). Note: legacy query cache .meta files are
      handled separately by wp_cache_post_change().
```

### Target Canonicalization
- Target paths and URLs are normalized via `turbo_cache_normalize_invalidation_target()`.
- Windows backslashes are normalized to forward slashes.
- Multiple consecutive slashes and trailing slashes are harmonized.
- Empty path in absolute URLs (e.g., `http://example.com`) falls back to `/`.
- Direct-page cache directories are mapped to `ABSPATH . {page}/` without depending on `$_SERVER['REQUEST_URI']`.
- Host and scheme normalization ensures targets match the exact supercache directory layout (`wp-content/cache/supercache/{host}/{path}`).

---

## 3. Return-Value Contract & Deduplication Architecture

A single mutation in WordPress often triggers multiple hooks in sequence:
`wp_insert_post` -> `clean_post_cache` -> `transition_post_status` -> `edit_post` -> `save_post`.

To eliminate redundant disk I/O and duplicate purge calls, Turbo Cache employs a request-local deduplication register:
- **Function:** `turbo_cache_deduplicate_purge( $target, $action = 'mark' )`
- **Actions:**
  - `'mark'`: Checks if target was purged; if not, marks it and returns `true`. If already purged, returns `false`.
  - `'check'`: Non-marking check. Returns `true` if eligible to purge (not yet marked), `false` if already purged.
  - `'remove'`: Unmarks target (used on I/O error rollback via `turbo_cache_unmark_purged`).
- **Success Contract:**
  - `turbo_cache_purge_storage_dir( $dir, $delete_dir = true, &$success = null )`: Returns `int` (count of unlinked cache files). `$success` is passed by reference: `true` on success or when directory did not exist (already clear), `false` on directory traversal or unlink failure.
  - `turbo_cache_purge_url( $url )`: Returns `bool`. Returns `true` if purged or already empty; returns `false` if target was invalid, already deduplicated, or encountered filesystem failure (`$success === false`). On failure, deduplication is rolled back with `turbo_cache_unmark_purged()` so subsequent retries are not suppressed.
  - `turbo_cache_invalidate_homepage()`: Returns `bool`. Only marks `'homepage:0'` as permanently purged if filesystem deletion succeeds. If deletion fails, rolls back deduplication to allow retry in the same process.
- **Testing & Reset:** `turbo_cache_reset_purged_targets()` clears both deduplication and purged targets registries.

---

## 4. Old Permalink Invalidation (`post_updated` & `set_object_terms`)

### Slug & Hierarchy Changes
When a published post or page changes its slug (`post_name`) or parent hierarchy (`post_parent`), WordPress creates a permalink transition:
- `turbo_cache_post_updated()` intercepts the `post_updated` hook with `$post_after` and `$post_before`.
- If the post status was published, it resolves the previous public URL using `turbo_cache_resolve_post_object_url( $post_before )` and purges the old cache directory.
- It also resolves the new public URL and invalidates the new cache directory.

### Category Changes (`set_object_terms`)
When posts utilize a custom permalink structure containing `%category%` (e.g., `/%category%/%postname%/`):
- Changing the assigned category changes the post's public permalink without changing scalar fields in `wp_posts`.
- `turbo_cache_terms_changed()` intercepts `set_object_terms` for the `'category'` taxonomy.
- It resolves all terms in `$old_tt_ids` and sorts them by `term_id ASC` (strictly matching WordPress core `get_permalink()` in `wp-includes/link-template.php`), accounting for hierarchical parent chains via `get_category_parents()`.
- If the old URL differs from the new canonical URL, it purges the old permalink storage.
- It then unmarks and calls `turbo_cache_invalidate_post($object_id)`, purging the new permalink cache and cleaning related archives.
- When the permalink structure does not contain `%category%`, the URL remains unchanged, but `turbo_cache_invalidate_post()` is still invoked to refresh post content and category archives.

---

## 5. Permanent Deletion from Trash (`before_delete_post`)

In WordPress Core (`wp_delete_post`), permanent deletion follows this sequence:
1. `before_delete_post` fires with the post ID and intact post object.
2. Metadata is deleted (`delete_post_meta($post_id, '_wp_trash_meta_status')` runs).
3. The post row is deleted from `wp_posts`.
4. `delete_post` fires with the post ID, at which point `_wp_trash_meta_status` has already been deleted!
5. Trashed posts have slugs altered to `slug__trashed`, causing `get_permalink()` to fall back to `?p=123`.

**Solution:**
- Turbo Cache hooks `before_delete_post` at priority 0.
- It checks `_wp_trash_meta_status` while metadata is still intact.
- Never-published drafts (status `draft` or `auto-draft`) are skipped without public cache invalidation.
- For posts previously published or private:
  - The slug suffix `__trashed` is cleanly stripped.
  - The canonical URL is resolved using a temporary published clone with `get_sample_permalink()` so WordPress does not return a query URL.
  - The original public URL, homepage (if applicable), and archive pages are purged.
- On `delete_post`, `wp_cache_post_edit()` safely bails on draft/trashed status without redundant work.

---

## 6. WP-CLI and Cron Hook Registration

In CLI/cron environments:
- `advanced-cache.php` and `wp-cache-base.php` execute very early during bootstrap before WordPress defines `get_option()`.
- Consequently, `$WPSC_HTTP_HOST` was empty and `$cache_enabled` was set to `false`.
- When `wpsc_register_post_hooks()` ran later on `init`, it saw `$cache_enabled === false` and aborted without registering invalidation hooks!
- **Solution:** `wpsc_register_post_hooks()` and `wp-cache-base.php` synchronize `$WPSC_HTTP_HOST` from `get_option('home')` and reload `$cache_enabled` from `$wp_cache_config_file` if it was disabled solely due to early CLI bootstrap. When caching is genuinely disabled in the config file, `$cache_enabled` remains `false` and hooks remain unregistered as intended.

---

## 7. Homepage Invalidation Rules

The homepage is one of the highest-traffic targets on a site. Indiscriminate purging of the homepage degrades performance.
Turbo Cache implements intelligent front page targeting via `turbo_cache_post_affects_homepage( $post_id )`:

1. **Standard Posts (`post` type):**
   - By default, WordPress shows latest posts on the front page (`show_on_front === 'posts'`).
   - Updating or publishing a standard post invalidates the homepage.
2. **Static Front Page (`page_on_front`):**
   - If a static page is designated as the front page, updating that specific page invalidates the homepage.
   - If a page is designated as the posts page (`page_for_posts`), updating a standard post invalidates that page.
3. **Unrelated Pages (`page` type):**
   - Updating an unrelated static page (e.g. `/sample-page-2/`) does **not** invalidate the homepage.
4. **Failure Rollback:** If purging the homepage storage fails due to filesystem errors, the deduplication flag is rolled back to allow immediate retry.

---

## 8. Direct-Page Cache Invalidation

Direct-page caches are static files stored directly under `ABSPATH . {page}/` rather than the `supercache` directory.
- `turbo_cache_get_storage_dir()` inspects `$cached_direct_pages` against the normalized URI path.
- Invalidation works reliably from administrative contexts (`wp-admin`), WP-CLI, and WP-Cron without depending on `$_SERVER['REQUEST_URI']`.
- Direct-page directories under `ABSPATH` are protected so that cache files are removed while the directory itself is preserved.

---

## 9. Legacy Purge Compatibility (`wpsc_delete_url_cache`)

- When `$cache_rebuild_files` is enabled, `wpsc_delete_url_cache()` preserves upstream rebuild staging behavior by invoking `wpsc_rebuild_files()` and pruning paginated archives.
- When rebuild staging is not active, calls route through `turbo_cache_purge_url()` with deduplication and targeted directory purging.
- Redundant calls to `wpsc_delete_post_archives()` and duplicate disk sweeps in `wp_cache_post_id_gc()` are prevented via deduplication checking.

---

## 10. Verification Matrix: Mocks vs Real WordPress Integration

| Scenario | Unit / Smoke Test Double | Real WordPress Environment | Outcome |
| :--- | :--- | :--- | :--- |
| Permanent trash deletion (previously published) | `test_permanent_deletion_from_trash_purges_cache` | Live Scenario 6 (`live_tests.php`) | **PASS** |
| Permanent trash deletion (never-published draft) | `test_permanent_deletion_of_draft_skips_purge` | Live Scenario 6B (`live_tests.php`) | **PASS** (Skipped purge) |
| Post slug change invalidates old URL | `test_post_slug_change_invalidates_old_and_new_urls` | Live Scenario 2 (`live_tests.php`) | **PASS** |
| Hierarchical page-parent change | `test_page_parent_change_invalidates_old_and_new_urls` | Live Scenario 3 (`live_tests.php`) | **PASS** |
| Homepage purge failure and retry | `test_homepage_failed_purge_allows_retry` | Filesystem I/O test (`chmod 0444`) | **PASS** |
| Category permalink changes (`%category%`) | `test_category_change_invalidates_old_and_new_urls_independently`, `test_category_change_with_multiple_categories_selects_correct_permalink_category`, `test_category_change_with_hierarchical_category_parent`, `test_category_removal_or_replacement_invalidates_cache`, `test_permalink_structure_without_category_token_invalidates_post_and_archives`, `test_category_change_when_permalink_category_does_not_change` | Live Laragon WordPress (`D:\laragon\www\turbo-baseline`) | **PASS** (Live + Unit/Smoke) |
| CLI / Cron hook registration | `test_cli_hook_registration_and_disabled_cache_preservation` | WP-CLI live execution (`test_cli_sync.php`) | **PASS** |
| Cache rebuild staging compatibility | `test_wpsc_delete_url_cache_rebuild_compatibility` | Live Scenario 10 (`live_tests.php`) | **PASS** |
| Direct page invalidation from admin context | `test_direct_page_cache_invalidation_from_admin_context` | Live Scenario 9 (`live_tests.php`) | **PASS** |
| WooCommerce cart/session bypass | — | Live Scenario 8 (`live_tests.php`) | **PASS** |

---

## 11. Known Limitations

1. **Race Conditions:** Stale-write race conditions (simultaneous background regeneration while a mutation is running) are not addressed in Phase 5B.
2. **Taxonomy Archives:** Term and taxonomy-specific invalidations continue to rely on upstream legacy archive deletion.
3. **Preload:** Preload triggers are not invoked automatically upon single-target invalidation.
4. **Multisite:** Multisite compatibility is supported by code architecture, but **multisite support has not been verified with dedicated multisite tests** because a WordPress multisite testbed is not configured in this environment.
