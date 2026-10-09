# Phase 5B — WordPress Core Invalidation Hooks

## Overview

Phase 5B connects the WordPress Core content mutation lifecycle to the Turbo Cache targeted invalidation engine. The core module [inc/cache-invalidation.php](file:///d:/turbo-cache/inc/cache-invalidation.php) was introduced in Phase 5B (commit `3651faa`), establishing dedicated procedural functions for targeted invalidation, path canonicalization, request-local deduplication, and lifecycle integrations.

The goal of this phase is strictly scoped to WordPress Core standard content mutations (posts and pages) while preserving upstream WP Super Cache compatibility, avoiding premature architectural additions, and ensuring that unrelated cached content remains untouched.

---

## 1. Connected WordPress Hooks & Lifecycle Registrations

Turbo Cache hooks are registered in [wp-cache-phase2.php](file:///d:/turbo-cache/wp-cache-phase2.php) (`wpsc_register_post_hooks`) and connect into [inc/cache-invalidation.php](file:///d:/turbo-cache/inc/cache-invalidation.php):

| Hook / Trigger | Callback / Entrypoint | Priority | Trigger Timing & Condition | Invalidation Targets |
| :--- | :--- | :--- | :--- | :--- |
| `clean_post_cache` | `wp_cache_post_edit($post_id)` | 10 | Core post cache flush (updates, deletes, status transitions). | Canonical Post/Page URL + Homepage (if post affects front page). |
| `transition_post_status` | `wpsc_post_transition($new_status, $old_status, $post)` | 10 | Status transition (e.g. `draft -> publish`, `publish -> trash`, `publish -> draft`). | Canonical Post/Page URL + Homepage via `turbo_cache_post_status_transition()`. |
| `post_updated` | `turbo_cache_post_updated($post_id, $post_after, $post_before)` | 10 | Post update before/after comparison. | Previous canonical URL (slug/parent hierarchy changes) and new canonical URL. |
| `publish_post` | `wp_cache_post_edit($post_id)` | 0 | Post publication. | Canonical Post URL via `turbo_cache_invalidate_post()` + upstream archive cleanup. |
| `edit_post` | `wp_cache_post_change($post_id)` | 0 | Direct post edit. | Clears legacy query cache `.meta` files and archives. |
| `delete_post` | `wp_cache_post_edit($post_id)` | 0 | Post deletion (including permanent deletion from trash). | Residual canonical Post URL via `turbo_cache_invalidate_post()`. |
| `wp_trash_post` | `wp_cache_post_edit($post_id)` | 0 | Post moved to trash. | Canonical Post URL via `turbo_cache_invalidate_post()`. |
| `wpsc_delete_url_cache` | `wpsc_delete_url_cache($url)` | — | Helper called when a specific URL cache must be purged. | Preserves legacy staging if `$cache_rebuild_files` is set; otherwise calls `turbo_cache_purge_url($url)`. |

---

## 2. Invalidation Flow & Target Resolution

The invalidation path follows a strict procedural pipeline:

```text
WordPress Mutation (e.g., save_post / clean_post_cache / transition_post_status / post_updated)
    ↓
Target Identification:
    - Current Canonical URL: turbo_cache_get_post_canonical_url( $post_id )
    - Previous Canonical URL (on post_updated slug/parent change): turbo_cache_resolve_post_object_url()
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
  - `turbo_cache_purge_url( $url )`: Returns `bool`. Returns `true` if purged or already empty; returns `false` if target was invalid, already deduplicated, or encountered filesystem failure (`$success === false`). On failure, deduplication is unmarked so subsequent retries are not suppressed.
- **Testing & Reset:** `turbo_cache_reset_purged_targets()` clears both deduplication and purged targets registries.

---

## 4. Old Permalink Invalidation (`post_updated`)

When a published post or page changes its slug (`post_name`) or parent hierarchy (`post_parent`), WordPress creates a permalink transition:
- `turbo_cache_post_updated()` intercepts the `post_updated` hook with `$post_after` and `$post_before`.
- If the post status was published, it resolves the previous public URL using `turbo_cache_resolve_post_object_url( $post_before )` and purges the old cache directory.
- It also resolves the new public URL and invalidates the new cache directory.

---

## 5. Permanent Deletion from Trash (`delete_post`)

When a post is permanently deleted from the trash:
- The post status is `trash`.
- `wp_cache_post_edit()` checks `_wp_trash_meta_status`. If the post was previously `publish` or `private`, invalidation proceeds to purge any residual cache files associated with that post.
- Drafts and never-published posts remain safely skipped.

---

## 6. Homepage Invalidation Rules

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

---

## 7. Direct-Page Cache Invalidation

Direct-page caches are static files stored directly under `ABSPATH . {page}/` rather than the `supercache` directory.
- `turbo_cache_get_storage_dir()` inspects `$cached_direct_pages` against the normalized URI path.
- Invalidation works reliably from administrative contexts (`wp-admin`), WP-CLI, and WP-Cron without depending on `$_SERVER['REQUEST_URI']`.
- Direct-page directories under `ABSPATH` are protected so that cache files are removed while the directory itself is preserved.

---

## 8. Legacy Purge Compatibility (`wpsc_delete_url_cache`)

- When `$cache_rebuild_files` is enabled, `wpsc_delete_url_cache()` preserves upstream rebuild staging behavior by invoking `wpsc_rebuild_files()` and pruning paginated archives.
- When rebuild staging is not active, calls route through `turbo_cache_purge_url()` with deduplication and targeted directory purging.
- Redundant calls to `wpsc_delete_post_archives()` and duplicate disk sweeps in `wp_cache_post_id_gc()` are prevented via deduplication checking.

---

## 9. Multisite Compatibility & Testing Status

- Storage paths in Turbo Cache isolate domains and subdirectories by host and blog path (`$cache_path . 'supercache/' . $wp_cache_sub_blog_dir`).
- Directory traversal safety checks (`turbo_cache_is_safe_purge_dir()`) prevent cross-blog cache contamination.
- **Verification Status:** Multisite compatibility is supported by code architecture, but **multisite support has not been verified with dedicated multisite tests** because a WordPress multisite testbed is not configured in this environment.

---

## 10. Known Limitations

1. **Race Conditions:** Stale-write race conditions (simultaneous background regeneration while a mutation is running) are not addressed in Phase 5B.
2. **Taxonomy Archives:** Term and taxonomy-specific invalidations continue to rely on legacy archive deletion.
3. **Preload:** Preload triggers are not invoked automatically upon single-target invalidation.
