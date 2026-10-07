# Turbo Cache — Request Safety & WooCommerce Bypass Layer

## 1. Overview & Purpose

In standard WP Super Cache (upstream 3.1.4), anonymous static HTML caching works out-of-the-box for general pages, blog posts, and archives. However, upstream WP Super Cache has no built-in awareness of dynamic WooCommerce session and cart states.

When a customer or guest adds items to their shopping cart, WooCommerce sets client-side session and cart cookies (`woocommerce_items_in_cart`, `woocommerce_cart_hash`, and `wp_woocommerce_session_<hash>`). Under upstream WP Super Cache, when such visitors navigated to standard store pages (e.g., `/shop/`, product pages, or informational pages), Phase 1 immediately matched and served the static, anonymous cached HTML file from disk. Consequently, visitors observed empty mini-carts, cached customer notices, stale dynamic fragments, and inconsistent shopping sessions.

The **Request Safety Layer** introduces an ultra-fast, zero-dependency safety gate in Phase 1 before public cache lookup and delivery, ensuring dynamic e-commerce requests safely bypass public cache storage and delivery.

---

## 2. Upstream Baseline Behavior vs. Turbo Cache Behavior

| Aspect | Upstream (WP Super Cache 3.1.4) | Turbo Cache |
| :--- | :--- | :--- |
| **Anonymous GET on Content Pages** | Cached (HIT served from disk) | Cached (HIT served from disk) |
| **Logged-in User (`wordpress_logged_in_*`)** | Bypassed via `wpsc_is_caching_user_disabled` | Bypassed via `wpsc_is_caching_user_disabled` |
| **POST / PUT / DELETE Methods** | Bypassed | Bypassed |
| **REST API (`/wp-json/*`)** | Bypassed | Bypassed |
| **404 Not Found Pages** | Bypassed (not cached) | Bypassed (not cached) |
| **`/cart/` & `/checkout/`** | Bypassed (via `DONOTCACHEPAGE` in WP) | Bypassed (via `DONOTCACHEPAGE` in WP) |
| **Cookie: `woocommerce_items_in_cart`** | **SERVED FROM CACHE (Stale anonymous page served to shopper)** | **BYPASS (Passes to dynamic WordPress response)** |
| **Cookie: `woocommerce_cart_hash`** | **SERVED FROM CACHE** | **BYPASS (Passes to dynamic WordPress response)** |
| **Cookie: `wp_woocommerce_session_*`** | **SERVED FROM CACHE** | **BYPASS (Passes to dynamic WordPress response)** |

---

## 3. WooCommerce Cookie Rules & Detection

The safety check detects the presence of active shopping cart and session states by inspecting the `$_COOKIE` superglobal:

1. **`woocommerce_items_in_cart`**: Fast O(1) hash table lookup (`isset`). Indicates the visitor currently has products in their cart.
2. **`woocommerce_cart_hash`**: Fast O(1) hash table lookup (`isset`). Contains the hash of the customer's current cart contents.
3. **`wp_woocommerce_session_*`**: Prefix match (`strncmp($cookie_name, 'wp_woocommerce_session_', 23) === 0`).
   - `wp_woocommerce_session_abc` $\rightarrow$ **MATCH** (Bypass)
   - `wp_woocommerce_session_xyz` $\rightarrow$ **MATCH** (Bypass)
   - `some_wp_woocommerce_session_test` $\rightarrow$ **NO MATCH** (Exact prefix required; substrings outside the prefix do not trigger false bypasses)

Standard WordPress authentication cookies (`wordpress_logged_in_*`) and normal tracking cookies (`_ga`, etc.) do not trigger this rule; authentication bypass is preserved and handled through its canonical upstream auth checks.

---

## 4. Phase 1 Hot-Path Integration & Zero-Overhead Architecture

### 4.1 Execution Sequence

The safety check is executed on the **Phase 1 hot path** (`wp-cache-phase1.php`) before any cache lookup or file serving:

```text
Incoming HTTP Request
        ↓
wpsc_is_backend() (wp-admin, login, xmlrpc)
        ↓
wpsc_is_rejected_cookie() (custom rejected cookies)
        ↓
turbo_cache_detect_woocommerce_cookie() [TURBO CACHE SAFETY GATE]
   ├─ Match found:
   │    ├─ Define DONOTCACHEPAGE = 1
   │    ├─ Log debug reason if debug enabled
   │    └─ return true; (Bypass Phase 1 cache serve)
   └─ No match: Continue to next check
        ↓
wpsc_is_caching_user_disabled() (WordPress auth cookies)
        ↓
HTTP Method check (POST, PUT, DELETE)
        ↓
Cache lookup & delivery: wp_cache_serve_cache_file()
```

### 4.2 Why WordPress Core and WooCommerce are NOT Loaded in Phase 1

1. **Extreme Latency Constraints:** Phase 1 delivers cache hits in ~15ms – 30ms. Loading WordPress core (`wp-load.php`, database connections, options table, theme bootstrap) consumes 800ms – 1500ms on typical servers.
2. **Pure Superglobal Inspection:** The request cookie headers are already populated in `$_COOKIE` by PHP SAPI at process start. No database queries, hook callbacks, or class instantiations are required to inspect cookie keys.
3. **Defense in Depth:** In Phase 2 (`wp_cache_ob_callback`), `turbo_cache_detect_woocommerce_cookie()` is checked alongside `DONOTCACHEPAGE`. Even if Phase 1 were skipped, dynamic WooCommerce responses are guaranteed never to be written to disk as public cache files.

---

## 5. Verification & Test Matrix

All tests were executed against the live testbed at `http://localhost/turbo-baseline/` (Apache 2.4 + PHP 8.3 + WordPress 7.1.3 + WooCommerce 11.1.2):

### 5.1 Unit & Smoke Tests (`tests/php/smoke/TurboCacheWooCommerceCookieTest.php`)

* **Framework:** PHPUnit 9.6.36 (pure PHP, isolated smoke tier)
* **Total Assertions:** 91 tests, 132 assertions passed in 0.046s.
* **Test Cases:**
  * `test_woocommerce_items_in_cart_matches`: `VERIFIED`
  * `test_woocommerce_cart_hash_matches`: `VERIFIED`
  * `test_woocommerce_session_prefix_matches`: `VERIFIED`
  * `test_substring_session_does_not_match`: `VERIFIED`
  * `test_wordpress_auth_cookie_does_not_match_wc_check`: `VERIFIED`
  * `test_empty_cookies_returns_false`: `VERIFIED`
  * `test_cookie_named_zero_does_not_break_matching`: `VERIFIED`

### 5.2 Real HTTP Runtime Tests (`http://localhost/turbo-baseline/`)

| Test ID | Scenario / Request | Cookie Header | Expected Result | Observed Result | Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **TEST A1** | `GET /sample-page-2/` (First Request) | None | MISS (Dynamic execution) | `X-WP-Super-Cache: NONE`, 2.21s duration | `VERIFIED` |
| **TEST A2** | `GET /sample-page-2/` (Second Request) | None | HIT (Phase 1 Supercache) | `X-WP-Super-Cache: Served supercache file`, 37.08ms | `VERIFIED` |
| **TEST B** | `GET /sample-page-2/` | `woocommerce_items_in_cart=1` | BYPASS | `X-WP-Super-Cache: NONE`, 1.08s dynamic response | `VERIFIED` |
| **TEST C** | `GET /sample-page-2/` | `wp_woocommerce_session_test=session_token` | BYPASS | `X-WP-Super-Cache: NONE`, 1.01s dynamic response | `VERIFIED` |
| **TEST D** | `GET /cart/` (Repeated) | None | Non-cacheable | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **TEST E** | `GET /checkout/` (Repeated) | None | Non-cacheable (302 redirect) | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **TEST F1** | `GET /shop/` (First Request) | None | MISS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **TEST F2** | `GET /shop/` (Second Request) | None | HIT | `X-WP-Super-Cache: Served supercache file`, 33.60ms | `VERIFIED` |
| **TEST F3** | `GET /shop/` | `woocommerce_cart_hash=hash123` | BYPASS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **TEST F4** | `GET /product/test-physical-product/` (Req 1) | None | MISS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **TEST F5** | `GET /product/test-physical-product/` (Req 2) | None | HIT | `X-WP-Super-Cache: Served supercache file`, 52.74ms | `VERIFIED` |
| **TEST F6** | `GET /product/test-physical-product/` | `woocommerce_items_in_cart=1` | BYPASS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **REG 1** | Logged-in auth cookie | `wordpress_logged_in_test=admin` | BYPASS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **REG 2** | POST request | None | BYPASS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **REG 3** | REST API `/wp-json/wp/v2/posts` | None | BYPASS | `X-WP-Super-Cache: NONE` | `VERIFIED` |
| **REG 4** | 404 `/non-existent-page-turbo-12345/` | None | Non-cacheable | `X-WP-Super-Cache: NONE` | `VERIFIED` |

---

## 6. Performance Considerations

* **Hot Path Overhead:** When `$_COOKIE` is empty (anonymous guest without cookies), `empty($cookies)` exits in single-digit nanoseconds.
* **Exact vs. Iterative Check:** Exact cookies (`woocommerce_items_in_cart`, `woocommerce_cart_hash`) use O(1) hash lookups before looping over cookie keys.
* **No Regular Expressions:** Cookie prefix matching uses `strncmp($key, 'wp_woocommerce_session_', 23)` directly in C-speed native PHP execution instead of compiling and running regular expressions.
* **Zero Object Allocations:** Avoided heavy object-oriented abstractions or registries on the Phase 1 critical path.
