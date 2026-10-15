# Turbo Cache — Cache Delivery Performance & Conditional GET Layer

This document details the architecture, design decisions, and runtime verification for the Turbo Cache high-performance delivery layer and RFC 7232-compliant conditional GET validation engine introduced in Phase 6.

---

## 1. Context and Problem Statement

In upstream WP Super Cache (and its inherited PHP static cache delivery path):
1. **Zero HTTP ETag Support**: Static cache serving never emitted HTTP `ETag` headers.
2. **Missing RFC 7232 Conditional Validation**: Upstream completely ignored `If-None-Match`. When modern browsers, mobile clients, search engine crawlers, or CDNs (Cloudflare, Fastly) revisited cached pages with `If-None-Match: "<etag>"`, upstream always returned `HTTP 200 OK` and re-transferred the entire HTML payload across the network.
3. **Disabled 304 by Default**: Even for legacy `If-Modified-Since`, upstream gated the 304 response behind `$wp_supercache_304`, which defaults to `0` (disabled), causing unnecessary network transfers.
4. **Inefficient Late Evaluation**: In upstream code, `If-Modified-Since` was evaluated only *after* the static file was read into PHP string memory via `file_get_contents()` and optionally gzipped via `gzencode()`.
5. **Missing Content-Length**: On uncompressed static cache delivery without `$wp_supercache_304`, upstream failed to set `Content-Length`, impairing HTTP Keep-Alive connection reuse.

---

## 2. Architecture & Design Principles

The delivery layer is housed in [`inc/cache-delivery.php`](file:///d:/turbo-cache/inc/cache-delivery.php) and integrated into the request serving pipeline in [`wp-cache-phase2.php`](file:///d:/turbo-cache/wp-cache-phase2.php) and [`wp-cache-phase1.php`](file:///d:/turbo-cache/wp-cache-phase1.php).

### 2.1 RFC 7232 Strong ETag Generation
* **Function:** `turbo_cache_generate_etag( $mtime, $size )`
* **Format:** `'"' . dechex( $mtime ) . '-' . dechex( $size ) . '"'`
* Combines hex-encoded file modification time and file size into a quoted entity tag.
* Deterministic: Identical cache states always generate the exact same ETag. Any modification or size delta immediately produces a unique ETag.

### 2.2 Normalized If-None-Match Parsing
* **Function:** `turbo_cache_parse_if_none_match( $header )`
* Handles:
  - Wildcard (`*`) matching.
  - Weak comparison indicators (`W/"..."` vs `"..."`) per RFC 7232 Section 3.2.
  - Comma-delimited lists of multiple ETags from clients.
  - Double and single quote stripping without over-trimming digits or alphanumeric characters.

### 2.3 Early Conditional Evaluation
* Evaluated immediately in `wp_cache_serve_cache_file()` before reading file contents into PHP memory buffers.
* **Precedence (RFC 7232 § 3.3):**
  - If `If-None-Match` is present, it is evaluated first. If it matches, `304 Not Modified` is emitted immediately. If it fails, `If-Modified-Since` is *not* evaluated.
  - If `If-None-Match` is absent, `If-Modified-Since` is evaluated against file modification time.
* If conditional validation succeeds:
  - Calls `turbo_cache_send_conditional_304( $etag, $mtime )`.
  - Sets `HTTP/1.1 304 Not Modified`, `ETag`, `Last-Modified`, `Vary: Accept-Encoding, Cookie`, `Cache-Control`, `X-Turbo-Cache: HIT (304 Not Modified)`.
  - Terminates request cleanly with **0 bytes** of body transferred and **0 bytes** of memory buffered.

### 2.4 Modern 200 Delivery Headers
* When serving full cached content (`HTTP 200`):
  - Emits `ETag: "<etag>"`.
  - Emits `Last-Modified: <rfc7231-date>`.
  - Emits `Content-Length: <bytes>` for both compressed and uncompressed payloads.
  - Emits `X-Turbo-Cache: HIT (supercache)` while preserving `X-WP-Super-Cache` for backward compatibility.

### 2.5 Safety & WooCommerce Protections
* When dynamic tags (`$wp_cache_mfunc_enabled == 1`) are detected, conditional 304 is bypassed because content may vary dynamically.
* When WooCommerce session or cart cookies are detected (`woocommerce_items_in_cart`, `wp_woocommerce_session_*`, etc.), request safety guards bypass the cache engine entirely before delivery.

---

## 3. Verification & Evidence

### 3.1 Smoke Tests
* Test Suite: [`tests/php/smoke/TurboCacheDeliveryPerformanceTest.php`](file:///d:/turbo-cache/tests/php/smoke/TurboCacheDeliveryPerformanceTest.php)
* 17 unit/smoke tests covering:
  - Valid and deterministic ETag generation.
  - Boundary and invalid inputs.
  - Single, multiple, weak, and wildcard `If-None-Match` parsing.
  - Case-insensitive header extraction.
  - ETag hit, weak ETag hit, wildcard hit, and ETag miss.
  - RFC 7232 precedence enforcement.
  - `If-Modified-Since` hit, newer client timestamp hit, and outdated timestamp miss.
  - 304 response headers and 200 delivery headers.
  - Direct file streaming.
* Results: **194/194 tests passing across full PHPUnit suite** (430 assertions).

### 3.2 Laragon Runtime Testbed Verification
* **Environment:** Apache 2.4.66, PHP 8.3.30, MySQL 8.4.3, WordPress 7.1.3 + WooCommerce 11.1.2 at `http://localhost/turbo-baseline/`.
* **Initial Cache Hit:**
  ```http
  HTTP/1.1 200 OK
  Vary: Accept-Encoding,Cookie
  Cache-Control: max-age=3, must-revalidate
  X-WP-Super-Cache: Served supercache file from PHP
  Content-Length: 83898
  ETag: "6ad1256b-147ba"
  Last-Modified: Thu, 15 Oct 2026 19:11:39 GMT
  X-Turbo-Cache: HIT (supercache)
  Content-Type: text/html; charset=UTF-8
  ```
* **Conditional Re-request with Matching ETag (`If-None-Match: "6ad1256b-147ba"`):**
  ```http
  HTTP/1.1 304 Not Modified
  ETag: "6ad1256b-147ba"
  Last-Modified: Thu, 15 Oct 2026 19:11:39 GMT
  Vary: Accept-Encoding,Cookie
  Cache-Control: max-age=3, must-revalidate
  X-Turbo-Cache: HIT (304 Not Modified)
  X-WP-Super-Cache: Served supercache 304 file from PHP
  ```
* **WooCommerce Cookie Protection:**
  - Sending `Cookie: woocommerce_items_in_cart=1` alongside matching ETag correctly bypassed the cache and triggered dynamic execution with `<!-- WooCommerce dynamic cookie found ... -->`.
