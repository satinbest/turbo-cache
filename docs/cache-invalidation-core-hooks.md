# Phase 5B — WordPress Core Invalidation Hooks

## Overview

Phase 5B connects the WordPress Core content mutation lifecycle to the Turbo Cache targeted invalidation engine introduced in Phase 5A (`inc/cache-invalidation.php`).

The goal of this phase is strictly scoped to WordPress Core standard content mutations (posts and pages) while preserving upstream compatibility, avoiding premature architectural additions (such as taxonomy graph dependencies or WooCommerce-specific hooks), and ensuring that unrelated cached content remains untouched.

---

## 1. Connected WordPress Hooks

Turbo Cache connects to WordPress Core mutation hooks incrementally through upstream hooks and wrappers in [wp-cache-phase2.php](file:///d:/turbo-cache/wp-cache-phase2.php) and [inc/cache-invalidation.php](file:///d:/turbo-cache/inc/cache-invalidation.php):

| Hook / Trigger | Callback / Entrypoint | Trigger Timing & Condition | Invalidation Targets |
| :--- | :--- | :--- | :--- |
| `clean_post_cache` | `turbo_cache_invalidate_post($post_id)` | Fired by WordPress whenever post cache is cleared (core updates, deletes, status transitions). | Canonical Post/Page URL + Homepage (if post affects front page). |
| `transition_post_status` | `wpsc_post_transition($new_status, $old_status, $post)` | Fired whenever a post changes status (e.g. `draft -> publish`, `publish -> trash`, `publish -> draft`). | Canonical Post/Page URL + Homepage (if affects front page) via `turbo_cache_post_status_transition()`. |
| `publish_post` / `edit_post` | `wp_cache_post_edit($post_id)` | Legacy WP Super Cache hook invoked when a post is edited or published. | Canonical Post URL via `turbo_cache_invalidate_post()` + upstream archive cleanup via `wpsc_delete_post_archives()`. |
| `delete_post` / `wp_trash_post` | `wp_cache_post_id_gc($post_id)` | Fired when a post is deleted or moved to trash. | Canonical Post URL via `turbo_cache_invalidate_post()` + upstream archive cleanup. |
| `wpsc_delete_url_cache` | `wpsc_delete_url_cache($url)` | Action / helper called when a specific URL cache must be purged. | Canonical URL directory via `turbo_cache_purge_url()`. |

---

## 2. Invalidation Flow & Target Resolution

The invalidation path follows a strict procedural pipeline:

```text
WordPress Mutation (e.g., save_post / clean_post_cache / transition_post_status)
    ↓
Target Identification:
    - Canonical URL: turbo_cache_get_post_canonical_url( $post_id )
    - Homepage check: turbo_cache_post_affects_homepage( $post_id )
    ↓
Request-Local Deduplication:
    - turbo_cache_deduplicate_purge( $canonical_target )
    ↓ (if not already purged during request)
Targeted Purge:
    - Post URL Directory Purge: turbo_cache_purge_url( $url )
    - Homepage Purge (if applicable): turbo_cache_invalidate_homepage()
    ↓
Disk Cleanup:
    - turbo_cache_purge_storage_dir( $target_dir ) removes index.html, index.html.gz, .meta files
```

### Target Canonicalization
- Target paths and URLs are normalized via `turbo_cache_normalize_invalidation_target()`.
- Windows backslashes are normalized to forward slashes.
- Multiple consecutive slashes and trailing slashes are harmonized.
- Case normalization is applied to path comparisons on case-insensitive filesystems (Windows).
- Host and scheme normalization ensures targets match the exact supercache directory layout (`wp-content/cache/supercache/{host}/{path}`).

---

## 3. Deduplication Architecture

A single mutation in WordPress often triggers multiple hooks in sequence:
`wp_insert_post` -> `clean_post_cache` -> `transition_post_status` -> `edit_post` -> `save_post`.

To eliminate redundant disk I/O and duplicate purge calls, Turbo Cache employs a request-local deduplication register:
- **Function:** `turbo_cache_deduplicate_purge( $target )`
- **Scope:** Request-local in-memory array (`static $purged = array()`).
- **Target Key:** Normalized canonical storage path or canonical URL.
- **Result:** The first hook invocation executes the purge and registers the target. Subsequent hooks encountering the same target within the same request safely short-circuit with `false`.
- **Testing:** `turbo_cache_reset_purged_targets()` is provided for test isolation and debugging.

---

## 4. Homepage Invalidation Rules

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

## 5. Unrelated Cache Preservation

A core guarantee of Phase 5B is that targeted invalidation must **never** inadvertently flush unrelated content.
In runtime verification:
- Mutating Post 1 (`/hello-world/`) invalidates Post 1 and the Homepage, but leaves `/sample-page/` and `/sample-page-2/` untouched.
- Mutating Page 10 (`/sample-page-2/`) invalidates only `/sample-page-2/`, preserving Post 1, Page 2, and the Homepage.

---

## 6. Hooks Intentionally Left Upstream / Deferred

The following hooks were evaluated and intentionally left untouched or deferred:

| Hook / Area | Current Handling | Rationale for Deferral |
| :--- | :--- | :--- |
| `wpsc_delete_post_archives` | Upstream legacy logic retained | Archive invalidation (category, tag, date, author) relies on upstream loop. Full graph-aware archive purging requires Phase 6 dependency graph. |
| `edit_term`, `create_term`, `delete_term` | Upstream legacy logic retained | Taxonomy invalidation will be addressed in a dedicated taxonomy phase to avoid over-purging. |
| Comment approval / status hooks | Upstream legacy logic retained | Comment status transitions trigger `clean_post_cache` via WordPress core, which already connects to `turbo_cache_invalidate_post()`. |
| `switch_theme`, `wp_update_nav_menu` | Upstream legacy logic retained | Global site alterations that purge whole cache; deferred to administrative cache management. |
| User profile hooks | Upstream legacy logic retained | User updates do not alter public guest page cache. |

---

## 7. WooCommerce Regression Status

WooCommerce integration is strictly out of scope for Phase 5B invalidation hooks. However, regression tests confirm that Phase 5B does not degrade existing WooCommerce caching or bypass rules:
- **Anonymous Shop / Product:** Correctly cached and served (`X-WP-Super-Cache: Served supercache file from PHP`).
- **WooCommerce Cart Cookie (`woocommerce_items_in_cart`):** Successfully bypasses cache (MISS / dynamic execution).
- **Logged-in User Cookie:** Successfully bypasses cache (MISS / dynamic execution).

---

## 8. Multisite Compatibility

- Cache storage paths in Turbo Cache isolate domains and subdirectories by host and blog path (`$cache_path . 'supercache/' . $wp_cache_sub_blog_dir`).
- Target directory safety checks (`turbo_cache_is_safe_purge_dir`) prevent cross-directory or root traversing purges.
- Subdirectory installations (e.g., `http://localhost/turbo-baseline/`) are fully supported by preserving site relative path segments.
- *Note:* Dedicated WordPress Multisite (subdomain/subfolder network) test suites require a dedicated multisite environment; compatibility is supported structurally but network-level testing is deferred until a multisite testbed is configured.

---

## 9. Known Limitations

1. **Race Conditions:** Stale-write race conditions (simultaneous background regeneration while a mutation is running) are not addressed in Phase 5B and are scheduled for a separate phase.
2. **Taxonomy Archives:** Term and taxonomy-specific invalidations rely on legacy archive deletion.
3. **Preload:** Preload triggers are not invoked automatically upon single-target invalidation.
