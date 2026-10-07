# Turbo Cache — Baseline Runtime Snapshot

This document records the exact runtime behavior, benchmark figures, and behavioral quirks observed from the official **WP Super Cache 3.1.4** running inside a live WordPress + WooCommerce environment.

---

## 1. Runtime Environment

* **Operating System:** Windows 11 (AMD64)
* **Web Server:** Apache 2.4.66 (Win64) OpenSSL/3.0.18 PHP/8.3.30 (Laragon)
* **PHP Binary & Version:** PHP 8.3.30 (cli/mod_php)
* **Database Server:** MySQL 8.4.3 (Win64) running on localhost:3306
* **Database Name:** `turbo_baseline`
* **Test WordPress Path:** `D:\laragon\www\turbo-baseline`
* **Plugin Location:** `D:\turbo-cache` (linked into `wp-content/plugins/wp-super-cache` via directory junction)

---

## 2. Software Versions

* **WordPress Core:** 7.1.3 (en_US)
* **WooCommerce:** 11.1.2
* **WP Super Cache:** 3.1.4 (WPSC_VERSION_ID: `1.12.1`)
* **Theme:** Twenty Twenty-Five (FSE default)

---

## 3. Configuration & Runtime Flags

* **`WP_CACHE`:** `true` (defined in `wp-config.php`)
* **`WPCACHEHOME`:** `D:/laragon/www/turbo-baseline/wp-content/plugins/wp-super-cache/`
* **`$cache_enabled`:** `true`
* **`$super_cache_enabled`:** `true`
* **`$wpsc_served_header`:** `true`
* **`$wp_cache_slash_check`:** `1` (required for permalinks with trailing slashes)
* **`$wp_cache_home_path`:** `'/turbo-baseline/'`
* **`$cache_max_time`:** `1800` (seconds)
* **Cache Path:** `D:/laragon/www/turbo-baseline/wp-content/cache/`

---

## 4. Observed Behaviors & Test Results

### 4.1 Anonymous Request (First Request — MISS)
* **Status:** `VERIFIED`
* **Behavior:** The page was executed dynamically through PHP and WordPress. At the end of the output buffer, WP Super Cache created a temporary file in the target directory and renamed it to `index.html`.
* **Execution Time:** ~1.06s – 1.15s
* **Footer Comment:** `<!-- Dynamic page generated in 1.063 seconds. -->`
* **Cache File Created:** `wp-content/cache/supercache/localhost/turbo-baseline/sample-page-2/index.html` (71,451 bytes).

### 4.2 Anonymous Request (Second Request — HIT)
* **Status:** `VERIFIED`
* **Behavior:** Served directly during Phase 1 (`wp_cache_serve_cache_file`) before WordPress core finished loading.
* **Response Header:** `X-WP-Super-Cache: Served supercache file from PHP`
* **Response Header:** `Cache-Control: max-age=3, must-revalidate`
* **Response Header:** `Vary: Accept-Encoding,Cookie`
* **Execution Time:** ~0.016s – 0.030s (16ms to 30ms total HTTP turnaround, ~50x faster than dynamic generation).

### 4.3 Logged-in User Request (BYPASS)
* **Status:** `VERIFIED`
* **Behavior:** When an auth cookie (e.g. `wordpress_logged_in_*`) was sent, Phase 1 identified the cookie via `wpsc_is_caching_user_disabled()` and `wp_cache_get_cookies_values()`.
* **Result:** The cached static file was bypassed. Full WordPress headers (`Link: <.../wp-json/...>`) were returned, and the request was processed dynamically.

### 4.4 POST Request (BYPASS)
* **Status:** `VERIFIED`
* **Behavior:** Sending an HTTP POST request triggered the guard in Phase 1 (`$_SERVER['REQUEST_METHOD'] === 'POST'`).
* **Result:** Phase 1 exited immediately. The response was generated dynamically without serving or modifying static page cache entries.

### 4.5 Query String Request (`?test=1`)
* **Status:** `VERIFIED`
* **Behavior:** Upstream WP Super Cache disables static supercache serving for non-empty GET requests (`wpsc_is_get_query()`).
* **Result:** It did NOT serve `supercache` static HTML. Instead, on subsequent calls it served legacy `WPCache` meta files (`X-WP-Super-Cache: Served WPCache cache file`).

### 4.6 404 Non-Existent Page
* **Status:** `VERIFIED`
* **Behavior:** Requested `/non-existent-page-12345/`.
* **Result:** Returned HTTP 404. WP Super Cache detected the 404 state and explicitly refused to write cache (`<!-- Page not cached by WP Super Cache. 404. -->`). No directory or file was created on disk.

### 4.7 REST API Request (`/wp-json/`)
* **Status:** `VERIFIED`
* **Behavior:** Returned `Content-Type: application/json; charset=UTF-8`.
* **Result:** WP Super Cache did not serve HTML page cache and did not append any HTML comments to the JSON payload.

### 4.8 WooCommerce Baseline
* **Shop Page (`/shop/`):** `VERIFIED` — Anonymous requests are cached as static `supercache` files.
* **Product Page (`/product/test-physical-product/`):** `VERIFIED` — Anonymous requests are cached as static `supercache` files.
* **Cart Page (`/cart/`):** `VERIFIED` — Not cached because WooCommerce defines `DONOTCACHEPAGE` in Phase 2 (`DONOTCACHEPAGE defined. Caching disabled.` logged in `wpsc_debug.log`).
* **Checkout Page (`/checkout/`):** `VERIFIED` — Not cached due to WooCommerce internal `DONOTCACHEPAGE`.
* **WooCommerce Cookies Bypass Limitation:** `VERIFIED` — **CRITICAL LIMITATION**: Sending `woocommerce_items_in_cart=1`, `woocommerce_cart_hash=...`, or `wp_woocommerce_session_*` cookies did **NOT** trigger a bypass in upstream WP Super Cache 3.1.4. The server served the anonymous cached `supercache` file anyway (`X-WP-Super-Cache: Served supercache file from PHP`).

### 4.9 Cache Invalidation
* **Status:** `VERIFIED`
* **Post Update:** Updated post title via WP-CLI. Both the post cache (`supercache/.../hello-baseline/index.html`) and the site front-page cache (`supercache/.../turbo-baseline/index.html`) were instantly deleted from disk.
* **Post Delete:** Trashed post via WP-CLI. The post's cache folder and files were purged immediately.

### 4.10 Expiration (TTL)
* **Status:** `VERIFIED`
* **Behavior:** Artificially aged the file timestamp of `sample-page-2/index.html` by 2 hours (past `$cache_max_time = 1800s`).
* **Result:** Phase 1 detected `filemtime + $cache_max_time < time()`, logged `Cache has expired and is older than 1800 seconds old.`, bypassed the stale file, executed Phase 2, and generated fresh cache content.

### 4.11 Cache Storage Architecture
* **Status:** `VERIFIED`
* **Directory Pattern:** `wp-content/cache/supercache/{HTTP_HOST}/{REQUEST_URI}/`
* **Atomic Rename:** Created `.tmp` file using `uniqid(wp_rand(), true)` and renamed to `index.html`.
* **Gzip:** Disabled by default in simple mode (`$cache_compression = 0`).

---

## 5. Performance Baseline Benchmark

Sequential requests to `http://localhost/turbo-baseline/` (Apache 2.4.66, PHP 8.3.30):

| Request # | Status | Total Time | StartTransfer (TTFB) | Cache Status | Header |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Warmup (MISS)** | 200 OK | 1.097s | 1.095s | MISS (Phase 2 dynamic generation) | None (rendered by WP) |
| **Run 1 (HIT)** | 200 OK | 0.017s | 0.015s | HIT (Phase 1 served from disk) | `X-WP-Super-Cache: Served supercache file from PHP` |
| **Run 2 (HIT)** | 200 OK | 0.016s | 0.014s | HIT (Phase 1 served from disk) | `X-WP-Super-Cache: Served supercache file from PHP` |
| **Run 3 (HIT)** | 200 OK | 0.016s | 0.014s | HIT (Phase 1 served from disk) | `X-WP-Super-Cache: Served supercache file from PHP` |
| **Run 4 (HIT)** | 200 OK | 0.023s | 0.020s | HIT (Phase 1 served from disk) | `X-WP-Super-Cache: Served supercache file from PHP` |
| **Run 5 (HIT)** | 200 OK | 0.025s | 0.022s | HIT (Phase 1 served from disk) | `X-WP-Super-Cache: Served supercache file from PHP` |

---

## 6. Known Upstream Limitations Identified

1. **No native WooCommerce session/cart cookie detection:** Customers with items in cart still receive anonymous static page caches for Shop, Home, and Products unless manually added to `$wpsc_rejected_cookies`.
2. **Curl `Accept: */*` rejection:** Phase 1 strictly requires `Accept` to match `text/html`. Clients sending `*/*` without `text/html` trigger unnecessary cache misses.
3. **Trailing slash permalink quirk:** Requires `$wp_cache_slash_check = 1` explicitly; otherwise URLs with trailing slashes fail the Phase 1 lookup.
4. **Monolithic state:** Configuration relies on line replacement (`wp_cache_replace_line`) inside `wp-cache-config.php`.
