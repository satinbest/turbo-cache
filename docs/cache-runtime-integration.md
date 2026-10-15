# Turbo Cache — Phase 5C Cache Runtime Integration

## 1. Overview & Purpose

Phase 5C completes the runtime integration of Turbo Cache's functional components into the core WordPress request and caching lifecycle:
- **Request Safety Layer** (`inc/request-safety.php`)
- **Cache Rules Dispatcher** (`turbo_cache_evaluate_early_request_rules`)
- **Functional Cache Storage Layer** (`inc/cache-storage.php`)
- **Response Delivery Engine** (`wp_cache_serve_cache_file`)
- **Output Buffering & Cache Generation** (`wp_cache_ob_callback`, `wp_cache_get_ob`)
- **Core Invalidation Lifecycle** (`inc/cache-invalidation.php`, `wpsc_register_post_hooks`)

---

## 2. Integrated Request Lifecycle

```text
Incoming HTTP Request
       │
       ▼
wp-content/advanced-cache.php
       │
       ▼
wp-cache-phase1.php
       │
       ├── turbo_cache_evaluate_early_request_rules()
       │      ├─ Method check (POST, PUT, DELETE)
       │      ├─ Backend/admin detection
       │      ├─ Query param rules (Customizer, secret token)
       │      └─ Cookie checks (WooCommerce cart/session, auth, rejected)
       │           └─ If BYPASS: defines DONOTCACHEPAGE = 1 ──> returns true
       │
       └── wp_cache_serve_cache_file() (Standard / Late Init)
              ├─ Check DONOTCACHEPAGE ──> BYPASS
              ├─ Check turbo_cache_check_request_safety() ──> BYPASS
              ├─ Check wpsc_get_accept_header() === 'text/html'
              ├─ Check wp_cache_get_cookies_values() !== '' ──> BYPASS
              ├─ Lookup via turbo_cache_get_cache_filepath()
              │
              ├── HIT  ──> Stream supercache file from disk (exit 0)
              └── MISS ──> Pass through to WordPress Core
                               │
                               ▼
WordPress Core Bootstrap (wp-settings.php: wp_cache_postload)
                               │
                               ▼
wp-cache-phase2.php (wp_cache_phase2)
       │
       ├── wpsc_register_post_hooks() (Phase 5B.1 Invalidation Wiring)
       └── ob_start( 'wp_cache_ob_callback' )
              │
              ▼
Dynamic Page Generation & Output Buffer Callback
       │
       ├── wp_cache_ob_callback() Guardrails
       │      ├─ DONOTCACHEPAGE check
       │      ├─ turbo_cache_detect_woocommerce_cookie()
       │      ├─ turbo_cache_rule_query_params()
       │      └─ Status codes, 404, redirect, known pages
       │
       └── wp_cache_get_ob() Storage Generation
              ├─ turbo_cache_get_storage_dir()
              ├─ turbo_cache_get_temp_filename() (Staging .tmp file)
              └─ turbo_cache_atomic_rename() (Atomic replacement with Windows fallback)
```

---

## 3. Concrete Gaps Resolved in Phase 5C

### 3.1 Cache Delivery Safety Gate (`wp_cache_serve_cache_file`)
* **Problem:** In upstream WP Super Cache, `wp_cache_serve_cache_file()` did not inspect `DONOTCACHEPAGE` or WooCommerce cookies. Under Late Init mode (`$wp_super_cache_late_init = 1`) or direct execution, requests with active shopping cart cookies were served anonymous cached pages.
* **Resolution:** Added early guardrails at entry of `wp_cache_serve_cache_file()`:
  - If `defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE`: aborts cache serving immediately.
  - If `turbo_cache_check_request_safety()` matches sensitive dynamic cookies: logs reason and returns `false`.

### 3.2 Dynamic Cookie Recognition (`wp_cache_get_cookies_values`)
* **Problem:** `wp_cache_get_cookies_values()` only evaluated WordPress auth cookies (`wordpress_logged_in_*`, `wp-postpass_*`, `comment_author_*`), returning empty string `""` for visitors with active WooCommerce carts. This resulted in `wp_cache_serve_cache_file()` considering shoppers anonymous.
* **Resolution:** Integrated `turbo_cache_detect_woocommerce_cookie()` into `wp_cache_get_cookies_values()`. If a WooCommerce cart or session cookie is present, a non-empty value is hashed, preventing false cache hits across all callers. Added optional `$reset` parameter to support clean test isolation.

### 3.3 Storage Layer Helper Integration
* **Filepath Resolution:** `wp_cache_serve_cache_file()` now resolves the target file via `turbo_cache_get_cache_filepath()`, unifying path resolution with the storage layer.
* **Temporary Filename Generation:** `wp_cache_get_ob()` utilizes `turbo_cache_get_temp_filename( $dir )` for atomic staging file creation.
* **Phase 2 Guardrails:** `wp_cache_ob_callback()` now guards against Customizer query parameters (`customize_changeset_uuid`) and secret bypass query tokens via `turbo_cache_rule_query_params()`.

---

## 4. Verification & Test Matrix

### 4.1 Smoke Test Suite (`tests/php/smoke/TurboCacheRuntimeIntegrationTest.php`)
* **Test Runner:** PHPUnit 9.6.36
* **Suite Total:** 177 tests, 366 assertions passed cleanly (0.409s).
* **Targeted Tests Added (with Process Isolation for constant definition):**
  - `test_serve_cache_file_bypasses_when_donotcachepage_defined`: `VERIFIED`
  - `test_serve_cache_file_bypasses_on_woocommerce_items_in_cart`: `VERIFIED`
  - `test_serve_cache_file_bypasses_on_woocommerce_cart_hash`: `VERIFIED`
  - `test_serve_cache_file_bypasses_on_woocommerce_session_cookie`: `VERIFIED`
  - `test_cookies_values_empty_for_anonymous`: `VERIFIED`
  - `test_cookies_values_captures_woocommerce_cart_cookie`: `VERIFIED`
  - `test_cookies_values_captures_woocommerce_session_cookie`: `VERIFIED`
  - `test_ob_callback_bypasses_on_woocommerce_cookie`: `VERIFIED`
  - `test_ob_callback_bypasses_on_customizer_query_param`: `VERIFIED`
  - `test_storage_layer_filepath_resolution`: `VERIFIED`

### 4.2 Live Laragon WordPress Verification
Environment: Apache 2.4.66, PHP 8.3.30, WordPress 7.1.3, WooCommerce 11.1.2 at `http://localhost/turbo-baseline/`:
1. **Anonymous Request (HIT):**
   - Header: `X-WP-Super-Cache: Served supercache file from PHP`
   - Observed: Served directly from disk in Phase 1.
2. **WooCommerce Items in Cart Cookie (`woocommerce_items_in_cart=1`):**
   - Result: Dynamic response generated; public cache bypassed.
   - Log: `wp_cache_get_cookies_values - found WooCommerce cookie: woocommerce_items_in_cart`
   - Log: `DONOTCACHEPAGE defined. Caching disabled.`
3. **WooCommerce Session Cookie (`wp_woocommerce_session_*`):**
   - Result: Dynamic response generated; public cache bypassed.
   - Log: `Caching disabled by early request rule: cookie:woocommerce:wp_woocommerce_session_test123`
