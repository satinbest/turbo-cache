# Turbo Cache — Cache Rules Layer Architecture

## 1. Overview & Purpose

The **Cache Rules Layer** introduces a unified, lightweight, functional dispatcher for Phase 1 early request cacheability checks.

Previously, early bypass decisions in `wp-cache-phase1.php` were scattered across multiple disjointed conditionals and duplicated boilerplate (`define('DONOTCACHEPAGE', 1); wp_cache_debug(...); return true;`). The Cache Rules Layer consolidates these checks into focused, independently testable predicate functions orchestrated by a single high-performance dispatcher: `turbo_cache_evaluate_early_request_rules()`.

---

## 2. Architectural Design & Boundaries

### 2.1 Conceptual Flow

```text
Incoming HTTP Request (Apache / PHP)
        │
        ▼
wp-content/advanced-cache.php
        │
        ▼
wp-cache-phase1.php
        │
        ▼
turbo_cache_evaluate_early_request_rules()
        │
        ├── BYPASS (string reason) ──> define('DONOTCACHEPAGE', 1) ──> wp_cache_debug() ──> return true;
        │
        └── false (Cacheable Request)
                 │
                 ▼
        wp_cache_serve_cache_file()
                 │
                 ├── HIT ──> Stream supercache file from disk (exit 0)
                 └── MISS ──> Pass through to WordPress dynamic generation
```

### 2.2 What the Dispatcher Does
* Consolidates early request-level rules before cache lookup.
* Evaluates non-cookie rules first (globally disabled, HTTP method, backend/system, URL parameters).
* Applies a high-speed shortcut for anonymous traffic: skips all cookie-dependent evaluations when `$_COOKIE` is empty.
* Provides deterministic, prioritized rule evaluation.
* Remains purely functional (no OOP objects, no registries, no dependency injection, zero runtime allocations).

### 2.3 What the Dispatcher Does NOT Do
To maintain cache correctness and prevent severe latency regressions, the following rules are **intentionally not evaluated** in the early dispatcher:
* **Query & Template Flags:** `is_404()`, `is_search()`, `is_feed()`, `is_archive()`, `is_single()`, `is_page()`. These require WordPress core `$wp_query` parsing.
* **Late In-Flight Signals:** `DONOTCACHEPAGE` defined dynamically by plugins or themes during page execution.
* **REST Context:** Complex REST detection requiring WordPress hooks (`REST_REQUEST`, `WC_API_REQUEST`).
* **Response Status Codes:** 301, 302, 304, 500 error detection.
* **Buffer & Output Integrity:** Fatal error detection (`wpsc_is_fatal_error()`) and closing `</html>` verification.
* **Storage & Delivery Logic:** File expiration (`filemtime`), Gzip encoding selection, HTTP `Accept` header verification, filesystem locks.
* **Phase 2 Deferred Filters:** `wp_cache_user_agent_is_rejected()` and `wp_cache_is_rejected()`. (Preserved in Phase 2 to prevent silent behavioral divergences).

---

## 3. Rule Ordering & Evaluation Hierarchy

Rules are evaluated deterministically from cheapest/most decisive to more complex:

```text
1. Globally Disabled Cache (turbo_cache_rule_cache_disabled)
   └─ Fast boolean check ($cache_enabled === false). If disabled, exit immediately.

2. HTTP Method (turbo_cache_rule_http_method)
   └─ Upstream matches POST, PUT, DELETE. Rejects non-cacheable write operations without parsing cookies.

3. Backend / Admin / System (turbo_cache_rule_backend_or_system)
   └─ Detects wp-admin, login, xmlrpc, wp-cron, CLI, and DOING_CRON.

4. Early Query Parameters (turbo_cache_rule_query_params)
   └─ Detects customize_changeset_uuid and secret bypass token (donotcachepage).

5. Cookie Optimization Gate (empty($_COOKIE) check)
   └─ If $_COOKIE is empty, immediately returns false (cacheable), skipping all cookie checks.

6. Cookie-Dependent Rules (when $_COOKIE is non-empty):
   ├─ 6a. WooCommerce Dynamic Cookies (turbo_cache_rule_woocommerce_cookies)
   │      Checks woocommerce_items_in_cart, woocommerce_cart_hash, wp_woocommerce_session_*
   ├─ 6b. WordPress Auth / Logged-in State (turbo_cache_rule_auth_cookies)
   │      Checks wordpress_logged_in_*, wp-postpass_*, comment_author_*
   └─ 6c. Rejected Cookies (turbo_cache_rule_rejected_cookies)
          Evaluates user-configured regex patterns in $wpsc_rejected_cookies
```

---

## 4. WooCommerce Dynamic Cookie Protection

The WooCommerce protections introduced in commit `0ba2779` remain fully active and prioritized within the rules dispatcher:
* **`woocommerce_items_in_cart`**: $O(1)$ `isset` check.
* **`woocommerce_cart_hash`**: $O(1)$ `isset` check.
* **`wp_woocommerce_session_*`**: $23$-character prefix check via native `strncmp()`.
* **Substrings ignored**: Cookie names like `some_wp_woocommerce_session_test` do not match.
* **Defense in Depth**: Phase 2 (`wp_cache_ob_callback`) retains its guardrail, ensuring WooCommerce dynamic pages are never stored as static cache files.

---

## 5. Verification & Test Matrix

All tests were executed against the test harness:
* **PHP:** PHP 8.3.30
* **Web Server:** Apache 2.4.66
* **WordPress:** 7.1.3
* **WooCommerce:** 11.1.2

### 5.1 Smoke & Unit Test Suite (`tests/php/smoke/`)
* **PHPUnit 9.6.36:** 104 tests, 151 assertions passed in 0.048s.
* **Rule Dispatcher Tests (`TurboCacheRulesLayerTest.php`):**
  * `test_cache_disabled_bypasses`: `VERIFIED`
  * `test_non_get_methods_bypass` (POST, PUT, DELETE): `VERIFIED`
  * `test_get_method_is_cacheable`: `VERIFIED`
  * `test_customizer_query_param_bypasses`: `VERIFIED`
  * `test_secret_bypass_param_matches`: `VERIFIED`
  * `test_wrong_secret_bypass_param_does_not_match`: `VERIFIED`
  * `test_empty_cookie_optimization_preserves_non_cookie_rules`: `VERIFIED`
  * `test_woocommerce_cookies_trigger_bypass`: `VERIFIED`
  * `test_woocommerce_false_substring_not_matched`: `VERIFIED`
  * `test_rule_priority_method_before_cookie`: `VERIFIED`
  * `test_rule_priority_cache_disabled_first`: `VERIFIED`
  * `test_rule_priority_query_param_before_cookie`: `VERIFIED`
  * `test_innocent_cookies_do_not_bypass`: `VERIFIED`

### 5.2 Live HTTP Verification (`http://localhost/turbo-baseline/`)

| Test ID | Scenario | Cookie / Method | Observed Result | Status |
| :--- | :--- | :--- | :--- | :--- |
| **1** | Anonymous Page MISS (`/sample-page-2/`) | None | `X-WP-Super-Cache: NONE` (2268ms dynamic) | `VERIFIED` |
| **2** | Anonymous Page HIT (`/sample-page-2/`) | None | `X-WP-Super-Cache: Served supercache file from PHP` | `VERIFIED` |
| **3** | Logged-in User Request | `wordpress_logged_in_test=admin` | `X-WP-Super-Cache: NONE` (BYPASS) | `VERIFIED` |
| **4** | WooCommerce Cart Cookie | `woocommerce_items_in_cart=1` | `X-WP-Super-Cache: NONE` (BYPASS) | `VERIFIED` |
| **5** | WooCommerce Session Cookie | `wp_woocommerce_session_tok123=abc` | `X-WP-Super-Cache: NONE` (BYPASS) | `VERIFIED` |
| **6a** | Shop Anonymous MISS (`/shop/`) | None | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **6b** | Shop Anonymous HIT (`/shop/`) | None | `X-WP-Super-Cache: Served supercache file from PHP` (36.22ms) | `VERIFIED` |
| **7a** | Product Anonymous MISS (`/product/.../`) | None | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **7b** | Product Anonymous HIT (`/product/.../`) | None | `X-WP-Super-Cache: Served supercache file from PHP` (29.14ms) | `VERIFIED` |
| **8** | POST Request | Method POST | `X-WP-Super-Cache: NONE` (BYPASS) | `VERIFIED` |
| **9** | REST API Request (`/wp-json/wp/v2/posts`) | None | `X-WP-Super-Cache: NONE` (BYPASS) | `VERIFIED` |
| **10** | 404 Error Page | None | `X-WP-Super-Cache: NONE` (NOT CACHED) | `VERIFIED` |
| **11** | Cache Invalidation | None | Cache file deleted on purge | `VERIFIED` |

---

## 6. Performance Benchmarks

Multi-sample HTTP measurements (5 samples per scenario) conducted on `http://localhost/turbo-baseline/`:

| Scenario | Measured Samples (ms) | Average Turnaround | Analysis |
| :--- | :--- | :--- | :--- |
| **Cache HIT (Empty Cookies)** | 30.04, 25.65, 25.43, 28.86, 25.78 | **27.15 ms** | Identical to baseline snapshot (~27ms). Zero hot-path regression. |
| **Cache HIT (Ordinary Cookies)** | 48.51, 61.71, 48.16, 52.15, 34.27 | **48.96 ms** | Fast cache delivery; ordinary cookies safely served from cache. |
| **WooCommerce Cookie BYPASS** | 1181.32, 915.02, 996.25, 1011.89, 988.89 | **1018.67 ms** | Correctly executes full dynamic WordPress + WooCommerce stack. |

---

## 7. Future Extension Points

The functional predicate structure enables straightforward future enhancements:
* **REST Path Early Detection:** Dedicated predicate for `/wp-json/` URI prefix.
* **Query Parameter Normalization:** Safe stripping of marketing tracking parameters (UTM, fbclid).
* **Currency & Language Cookies:** Multi-currency cookie variant rules.
* **Device / Mobile Detection:** Header-based mobile detection without regex overhead.
