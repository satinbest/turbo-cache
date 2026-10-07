# Turbo Cache — Cache Storage Layer Architecture

## 1. Overview & Purpose

The **Cache Storage Layer** (`inc/cache-storage.php`) provides a lightweight, procedural subsystem responsible for request identity resolution, path normalization, directory and filename construction, and atomic write operations.

Derived from upstream **WP Super Cache 3.1.4**, Turbo Cache modularizes these operations into pure, testable functional helpers while strictly maintaining byte-for-byte on-disk compatibility with the proven Supercache directory structure.

---

## 2. Architectural Design & Boundaries

### 2.1 Functional Organization

To ensure zero overhead on the Phase 1 critical hot path, this module avoids object-oriented abstractions, classes, interfaces, containers, registries, and runtime allocations.

```text
Incoming HTTP Request
        │
        ▼
wp-cache-phase1.php / wp-cache-phase2.php
        │
        ├── Request Identity: turbo_cache_get_request_identity()
        │      ├─ Normalized Host: turbo_cache_normalize_host()
        │      ├─ Normalized Path: turbo_cache_normalize_uri_path()
        │      └─ Scheme Flag: wpsc_is_https()
        │
        ├── Storage Directory: turbo_cache_get_storage_dir()
        │      └─ {cache_path}/supercache/{host}/{path}/
        │
        ├── Cache Filename: turbo_cache_get_cache_filename()
        │      └─ 'index' . ($is_https ? '-https' : '') . '.html'
        │
        └── Cache Filepath: turbo_cache_get_cache_filepath()
               └─ {storage_dir} . {cache_filename}
```

### 2.2 Functional Helper Responsibilities

| Helper Function | Input Parameters | Responsibility |
| :--- | :--- | :--- |
| `turbo_cache_normalize_uri_case()` | `$uri` | Uppercases `%xx` hex escapes and lowercases `[A-Z]+` ASCII letters, preserving raw UTF-8. |
| `turbo_cache_deep_replace()` | `$search`, `$subject` | Recursively removes target substrings (e.g. `..`, `\`, `index.php`). |
| `turbo_cache_normalize_uri_path()` | `$uri` | Strips query strings, hash fragments, unsafe characters, traversals, and normalizes case. |
| `turbo_cache_normalize_host()` | `?$host` | Strips explicit ports (`:8080`), lowercases host, and encodes entities (`ENT_COMPAT`). |
| `turbo_cache_get_request_identity()` | `?$uri`, `?$host`, `?$is_https` | Returns array `[ 'host' => ..., 'path' => ..., 'is_https' => ... ]`. Ensures path has leading slash. |
| `turbo_cache_get_cache_filename()` | `?$is_https` | Resolves `index.html` vs `index-https.html`, with support for mobile filter extensions. |
| `turbo_cache_get_storage_dir()` | `?$uri`, `?$host`, `?$cache_path` | Resolves absolute directory path: `{cache_path}/supercache/{host}/{path}/`. |
| `turbo_cache_get_cache_filepath()` | `?$uri`, `?$host`, `?$cache_path`, `?$is_https` | Combines resolved storage directory and cache filename. |
| `turbo_cache_path_is_too_long()` | `$path` | Validates path length against `PHP_MAXPATHLEN` (default 1024) with headroom. |
| `turbo_cache_get_temp_filename()` | `$dir` | Generates a unique staging filename ending in `.tmp`. |
| `turbo_cache_atomic_rename()` | `$tmp_file`, `$target_file` | Performs atomic rename with Windows fallback (`@unlink` then `@rename`). |
| `turbo_cache_atomic_write_file()` | `$target_file`, `$content`, `?$gzip_content` | Staged file writing with zero-byte validation, atomic rename, and gzip support. |

---

## 3. Supercache vs. WPCache Distinction

The WP Super Cache architecture maintains two distinct caching mechanisms:

1. **Supercache Mode (Static HTML Files):**
   * Primary high-performance path.
   * Filesystem layout: `wp-content/cache/supercache/{host}/{path}/index[-https].html[.gz]`.
   * Served directly from disk during Phase 1 in ~15ms–35ms without executing WordPress core.
   * Completely bypassed for requests with query strings (`wpsc_is_get_query()`).
2. **WPCache Mode (Legacy Dynamic PHP Cache):**
   * Secondary fallback path for non-empty GET queries and legacy cookie configurations.
   * File naming: `$file_prefix . $blogcacheid . md5(get_wp_cache_key()) . '.php'`.
   * Stored in `$blog_cache_dir` with companion JSON metadata file (`meta-*.php`).
   * **Boundary Guarantee:** Turbo Cache does not collapse or alter WPCache. The legacy MD5-based mechanism is preserved as-is.

---

## 4. Atomic Write & Race Safety Contract

### 4.1 Write Sequence
When generating a static Supercache file in Phase 2:
1. Cache content is written to a temporary staging file: `{dir}/{random_uuid}.tmp`.
2. File handle is closed (`fclose()`), flushing buffers to the OS.
3. Zero-byte protection verifies `@filesize($tmp) > 0`. If 0 bytes (e.g. aborted process or disk full), the temporary file is unlinked and discarded.
4. Atomic replacement via `turbo_cache_atomic_rename()`:
   * **POSIX:** `rename(old, new)` atomically overwrites the target file without intermediate states.
   * **Windows:** Because Windows NTFS blocks `rename()` over existing files, the fallback `@unlink($target)` followed by `@rename($tmp, $target)` is executed.
5. In Phase 1, readers exclusively request `index.html` or `index-https.html`. If a read occurs during the microsecond Windows rename window, `file_get_contents()` returns `false`, causing Phase 1 to fall back safely to dynamic WordPress generation. Partially written files are never served.

---

## 5. Compatibility Guarantees

* **On-Disk Directory Compatibility:** 100% identical to upstream WP Super Cache 3.1.4. Web server rewrite rules (Apache `.htaccess` / Nginx) and WP-CLI cache purge commands work without modification.
* **Upstream Function Compatibility:** `get_current_url_supercache_dir()`, `supercache_filename()`, `wpsc_normalize_uri_case()`, and `wpsc_deep_replace()` remain available with identical signatures and return values.
* **Query Strings:** Query strings are stripped from the Supercache directory path, but requests carrying query strings continue to bypass static Supercache serving via `wpsc_is_get_query()` unless tracking parameters are stripped.
* **HTTPS Separation:** Requests over HTTPS write to `index-https.html` and never share or overwrite HTTP `index.html`.
* **WooCommerce Safety:** The Phase 1 early request rules dispatcher intercepts shopping sessions and dynamic cookies (`woocommerce_items_in_cart`, `woocommerce_cart_hash`, `wp_woocommerce_session_*`) before storage lookup occurs.

---

## 6. Verification & Test Matrix

### 6.1 PHPUnit Test Suite (`tests/php/smoke/TurboCacheStorageTest.php`)
* **Total Suite:** 123 tests, 198 assertions passed in 0.077s.
* **Storage Tier Coverage:**
  * Root path normalization (`/` $\rightarrow$ `{host}/index.html`)
  * Normal subpages and trailing slash behavior
  * Duplicate slash collapsing
  * Case normalization (ASCII lowercase, percent-escape uppercase)
  * Literal space stripping vs `%20` preservation
  * Encoded slash `%2F` preservation
  * `index.php` stripping
  * Query string path handling
  * Port stripping (`example.com:8080` $\rightarrow$ `example.com`)
  * HTTP (`index.html`) vs HTTPS (`index-https.html`) filename resolution
  * Mobile variant suffix via `supercache_filename_str` filter
  * Filesystem path length protection (`PHP_MAXPATHLEN`)
  * Atomic write creation, cleanup of temporary files, and zero-byte rejection
  * Atomic file replacement (Windows fallback)
  * Dual-file write for uncompressed HTML + `.gz` companion

### 6.2 Live Runtime Verification (`http://localhost/turbo-baseline/`)

| Test ID | Scenario | Request Parameters | Header / Result | Status |
| :--- | :--- | :--- | :--- | :--- |
| **A1** | Anonymous Page MISS (`/sample-page-2/`) | None | Dynamic generation (2298ms), cache file written | Pass |
| **A2** | Anonymous Page HIT (`/sample-page-2/`) | None | `X-WP-Super-Cache: Served supercache file from PHP` (~29ms) | Pass |
| **B** | Logged-in Request | Cookie: `wordpress_logged_in_test=admin` | Dynamic WordPress generation (2158ms, BYPASS) | Pass |
| **C1** | WooCommerce Cart Cookie | Cookie: `woocommerce_items_in_cart=1` | Dynamic WooCommerce response (1199ms, BYPASS) | Pass |
| **C2** | WooCommerce Cart Hash | Cookie: `woocommerce_cart_hash=hash123` | Dynamic WooCommerce response (1051ms, BYPASS) | Pass |
| **C3** | WooCommerce Session Cookie | Cookie: `wp_woocommerce_session_tok=abc` | Dynamic WooCommerce response (1024ms, BYPASS) | Pass |
| **D** | HTTP Method POST | Method: POST | Dynamic WordPress response (879ms, BYPASS) | Pass |
| **E** | REST API Endpoint | URI: `/wp-json/wp/v2/pages` | JSON response (1277ms, NOT CACHED) | Pass |
| **F** | 404 Error Page | URI: `/non-existent-page-.../` | HTTP 404 (1778ms, NOT CACHED, no dir on disk) | Pass |
| **G1** | Query String Request (MISS) | URI: `/?test=1` | Dynamic generation (880ms), WPCache file created | Pass |
| **G2** | Query String Request (HIT) | URI: `/?test=1` | `X-WP-Super-Cache: Served WPCache cache file` (48ms) | Pass |
| **H1** | Shop Page Anonymous MISS | URI: `/shop/` | Dynamic generation (2157ms), cache file written | Pass |
| **H2** | Shop Page Anonymous HIT | URI: `/shop/` | `X-WP-Super-Cache: Served supercache file from PHP` (36ms) | Pass |
| **I1** | Product Page Anonymous MISS | URI: `/product/test-physical-product/` | Dynamic generation (1256ms), cache file written | Pass |
| **I2** | Product Page Anonymous HIT | URI: `/product/test-physical-product/` | `X-WP-Super-Cache: Served supercache file from PHP` (32ms) | Pass |

---

## 7. Performance Benchmarks

Multi-sample HTTP turnaround times measured on `http://localhost/turbo-baseline/` (Apache 2.4 + PHP 8.3):

* **Cache HIT (Empty Cookies):** 43.10ms, 54.58ms, 36.72ms, 32.93ms, 28.99ms $\rightarrow$ Average: **39.26 ms**
* **Cache HIT (Ordinary Cookies):** 37.37ms, 40.88ms, 54.36ms, 35.70ms, 35.56ms $\rightarrow$ Average: **40.77 ms**
* **WooCommerce Cookie BYPASS:** 1105.89ms, 1059.40ms, 831.39ms, 1036.82ms, 1066.64ms $\rightarrow$ Average: **1020.03 ms**

---

## 8. Platform Nuances & Caveats

1. **Windows NTFS Overwrite Limitation:** PHP's native `rename()` cannot atomically overwrite an existing file on Windows. The implementation uses an `@unlink` followed by `@rename` fallback.
2. **Trailing Slash Permalinks:** When `$wp_cache_slash_check == 1`, requests lacking a trailing slash fail Phase 1 lookup and fall through to WordPress core for a canonical 301 redirect.
3. **Local HTTPS Availability:** The local Apache environment at `http://localhost/turbo-baseline/` runs HTTP only; HTTPS scheme testing was verified through unit tests.
