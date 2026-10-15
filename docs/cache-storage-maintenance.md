# Turbo Cache — Cache Lifecycle & Storage Maintenance Architecture

This document details the architecture, design decisions, and runtime verification for the Turbo Cache lifecycle and storage maintenance layer introduced in Phase 7.

---

## 1. Problem Statement & Upstream Deficiencies

In inherited WP Super Cache code, garbage collection (GC) and cache expiration suffered from several critical operational defects:

1. **Unbounded, Unmetered Execution**:
   - `prune_super_cache()` performed an unconstrained recursive filesystem traversal.
   - On sites with large numbers of cached URLs (thousands or tens of thousands of files across posts, terms, and pagination archives), a single GC run would attempt to scan and stat every file and folder synchronously.
   - This frequently triggered PHP `max_execution_time` fatal errors (often 30s) or memory exhaustion, crashing GC runs, leaving mutex lock files behind, and spiking server disk I/O.
2. **Abandoned Temporary Files (`.tmp`) Left to Accumulate**:
   - When a PHP request crashed, was killed by an upstream web server timeout, or was interrupted while generating a cache file, unique temporary `.tmp` files were abandoned on disk.
   - Upstream lacked dedicated temporary file cleanup; `.tmp` files were treated as normal cache files subject to the full `$cache_max_time` (which could be hours or days).
3. **Empty Directory Orphanage**:
   - When all expired HTML and gzip files under a URL path were pruned, their parent directory remained empty on disk.
   - Upstream checked directory deletion via `@filemtime($entry) + $cache_max_time <= $now`. On Windows and modern filesystems, modifying or deleting child files does not necessarily make the directory timestamp older than the threshold, leaving thousands of empty directory trees orphaned indefinitely.
4. **Unguarded `apply_filters` Call**:
   - `wpsc_get_protected_directories()` called `apply_filters()` directly without checking `function_exists('apply_filters')`, throwing fatal errors in CLI or early bootstrap environments prior to WordPress core loading.

---

## 2. Architecture & Design Principles

The storage maintenance layer is implemented in [`inc/cache-maintenance.php`](file:///d:/turbo-cache/inc/cache-maintenance.php) and seamlessly integrated into [`wp-cache-phase2.php`](file:///d:/turbo-cache/wp-cache-phase2.php) and [`wp-cache-phase1.php`](file:///d:/turbo-cache/wp-cache-phase1.php).

### 2.1 Bounded, Resource-Metered Traversal
* **Function:** `turbo_cache_prune_storage( $directory, $options )`
* **Configurable Constraints:**
  - `max_files` (default: `500` during standard pruning, `0` / unlimited when forced): Stops traversal once the file deletion threshold is reached to prevent I/O saturation.
  - `time_limit` (default: `15.0` seconds): Tracks execution duration via `microtime(true)` and gracefully exits before reaching PHP maximum execution limits.
  - `max_age`: Strict expiration cutoff based on file modification timestamp (`filemtime + max_age <= now`).
* **Structured Metrics Return:**
  Returns detailed execution telemetry: `deleted_files`, `deleted_dirs`, `deleted_tmp`, `scanned_files`, `scanned_dirs`, `timed_out`, `hit_limit`, and `duration`.

### 2.2 Active Cache Preservation
* **Rule:** Valid cache files whose modification timestamp plus `max_age` is greater than `now` (`filemtime + max_age > now`) are **STRICTLY PRESERVED**.
* Active page caches are never pruned prematurely.

### 2.3 Empty Directory Cleanup
* **Function:** `turbo_cache_is_dir_empty( $dir )`
* When child cache files expire and are deleted, the maintenance worker checks if the enclosing directory has become completely empty.
* If empty and not a protected directory, it is safely unlinked via `@rmdir( $dir )`, eliminating directory bloat.

### 2.4 Stale Temporary File Cleanup
* Detects `.tmp` and `.tmp.gz` files created during atomic writes.
* If older than `tmp_max_age` (default: 300 seconds), they are identified as abandoned by dead processes and pruned immediately.
* Recent `.tmp` files (under active write) are strictly preserved.

### 2.5 Security and Boundary Protections
* **Function:** `turbo_cache_is_safe_maintenance_target( $path, $cache_root )`
* Enforces strict filesystem boundaries:
  - Rejects directory traversal attempts (`..`).
  - Verifies that target paths reside inside the authorized cache directory (or authorized direct page directories).
  - Protects root `.htaccess` and `index.html` guard files from accidental deletion.
  - Protects structural system roots (`cache_path`, `supercache`, `blogs`, `meta`).

### 2.6 Transparent Upstream Integration
* `prune_super_cache( $directory, $force = false, $rename = false )` in `wp-cache-phase2.php` delegates non-rename operations to `turbo_cache_prune_storage()`.
* Upstream callers (`wp_cache_gc_cron`, `wp_cache_phase2_clean_expired`, `wp_cache_clean_expired`) automatically benefit from bounded, crash-safe execution with zero changes to their function signatures or return types.

---

## 3. Verification & Results

### 3.1 Smoke Tests
* Test Suite: [`tests/php/smoke/TurboCacheStorageMaintenanceTest.php`](file:///d:/turbo-cache/tests/php/smoke/TurboCacheStorageMaintenanceTest.php)
* 14 focused tests verifying:
  - Boundary checking within cache directory.
  - Rejection of directory traversal and out-of-bounds targets.
  - Protection of root guard files (`.htaccess`, `index.html`).
  - Protection of structural directory roots.
  - Accurate empty vs. populated directory detection.
  - Strict preservation of fresh cache files (`filemtime + max_age > now`).
  - Accurate unlinking of expired files.
  - Clean removal of empty directories after expiration.
  - Retention of directories containing active fresh files.
  - Cleanup of stale `.tmp` files and protection of recent `.tmp` files.
  - Enforcement of `max_files` bounding.
  - Force pruning mode.
  - End-to-end integration with `prune_super_cache()`.
* **Full PHPUnit Suite Result:** **208 tests, 470 assertions, 0 errors, 0 failures**.

### 3.2 Laragon Testbed Live Verification
* Verified on `D:\laragon\www\turbo-baseline\wp-content\cache\supercache\localhost\turbo-baseline\`:
  - Created test directory containing expired `index.html` (2 hours old), stale `stale.tmp` (10 minutes old), and fresh `index.html` (0 seconds old).
  - Executed `prune_super_cache()` through live engine.
  - **Results Observed:**
    - Expired file was unlinked.
    - Stale temporary file was unlinked.
    - Empty directory was cleanly removed.
    - Fresh cache file was 100% preserved.
    - Populated fresh directory was 100% preserved.
