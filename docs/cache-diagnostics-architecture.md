# Turbo Cache — Cache Diagnostics and Observability Architecture

## 1. Overview and Core Principles

Phase 9 establishes a lightweight, privacy-preserving, evidence-based diagnostic and observability layer for Turbo Cache.

### Core Principles

1. **Zero External Dependencies & No Telemetry**:
   - Completely local to the WordPress installation.
   - Zero outbound HTTP calls, telemetry, tracking, or cloud dependencies.
2. **Evidence-Based Outcomes**:
   - Outcomes are recorded solely when confirmed by actual execution code paths.
   - An outcome is never inferred (e.g., a cache HIT is never declared merely because a file exists on disk, nor is cache generation declared successful merely because an HTTP request returned 200).
3. **Strict Privacy and Redaction**:
   - Zero sensitive cookie values, authentication tokens, passwords, nonces, or private page content in logs or diagnostic events.
   - Values of cookies and sensitive query parameters are stripped before recording.
4. **Near-Zero Overhead by Default (Zero Disk I/O)**:
   - Persistent logging to disk is disabled by default.
   - In-memory event recording for the active request uses lightweight bounded structures (sub-microsecond execution) and performs zero filesystem operations unless persistent logging is explicitly enabled.
5. **Bounded Storage**:
   - When persistent diagnostic logging is enabled, it operates as a circular ring buffer (FIFO) with a strict maximum entry count (100 entries) and file size cap (256 KB), preventing disk exhaustion.
6. **Administrative Security**:
   - All management endpoints require `manage_options` capability checks and nonce validation.

---

## 2. Canonical Diagnostic Vocabulary

Turbo Cache defines a standardized internal vocabulary across seven functional categories:

| Category | Diagnostic Outcome | Description |
| :--- | :--- | :--- |
| **Cache HIT** | `HIT_SUPERCACHE` | Static cache file served directly from disk via PHP loopback or rewrite. |
| | `HIT_SUPERCACHE_GZIP` | Pre-compressed gzip static file served directly from disk. |
| | `HIT_WPCACHE` | Phase 2 PHP object/meta cache served. |
| | `HIT_NOT_MODIFIED_304` | RFC 7232 conditional GET validation matched ETag or If-Modified-Since. |
| **Cache MISS** | `MISS_FIRST_VISIT` | No cache artifact exists on disk; forwarded to WordPress core to generate. |
| | `MISS_EXPIRED` | Cache file exists on disk but has exceeded TTL (`cache_max_time`). |
| | `MISS_FILE_EMPTY` | Cache file exists on disk but is 0 bytes or corrupt. |
| | `MISS_FILE_UNREADABLE` | Cache file exists on disk but cannot be read by the PHP process. |
| **Request Safety** | `BYPASS_METHOD` | Non-cacheable HTTP method (POST, PUT, DELETE). |
| | `BYPASS_BACKEND` | Request targets WordPress backend, admin, login, cron, or XML-RPC. |
| | `BYPASS_REST_OR_AJAX` | Request targets REST API (`/wp-json/`) or `admin-ajax.php`. |
| | `BYPASS_DISABLED` | Caching is globally disabled in configuration (`$cache_enabled = false`). |
| **Personalization** | `BYPASS_AUTH_COOKIE` | Logged-in WordPress user cookie detected (`wordpress_logged_in_*`). |
| | `BYPASS_WOOCOMMERCE_COOKIE`| Dynamic WooCommerce session or cart cookie detected. |
| | `BYPASS_COMMENT_AUTHOR` | Comment author cookie detected. |
| **Configured Rules** | `BYPASS_REJECTED_URI` | Request URI matches rejected URI string or regex pattern in settings. |
| | `BYPASS_REJECTED_UA` | User-Agent matches rejected crawler/browser pattern. |
| | `BYPASS_QUERY_PARAMS` | Query string matches cache bypass parameter (e.g. `preview`, `donotcachepage`). |
| | `BYPASS_CUSTOM_FILTER` | Filter hook or theme/plugin defined `DONOTCACHEPAGE`. |
| **Generation** | `GENERATION_SUCCESS` | Static cache file successfully written to disk at output buffering shutdown. |
| | `GENERATION_SKIPPED` | Page output evaluated as non-cacheable (e.g. 404, empty buffer, fatal error). |
| | `GENERATION_FAILED_FATAL`| Fatal PHP error interrupted page execution before completion. |
| | `GENERATION_FAILED_EMPTY`| Output buffer was empty (unhandled redirect or plugin conflict). |
| | `GENERATION_WRITE_FAILED`| Filesystem error preventing cache file creation or rename. |
| **Preload** | `PRELOAD_DISCOVERED` | Public URL discovered from content or XML sitemap. |
| | `PRELOAD_ATTEMPTED` | Preload HTTP request dispatched. |
| | `PRELOAD_HTTP_OK` | Preload HTTP request returned 200 OK. |
| | `PRELOAD_CACHED` | Valid static cache artifact physically verified on disk after request. |
| | `PRELOAD_NOT_CACHED` | Preload HTTP request returned 200 OK, but no valid cache file was created on disk. |
| | `PRELOAD_SKIPPED` | Public URL excluded before request (sensitive route, out of scope, invalid port). |
| | `PRELOAD_FAILED` | Preload HTTP request failed (connect error, timeout, 5xx server error). |

---

## 3. Data Redaction and Privacy

Before any diagnostic event is saved or exposed, it passes through `turbo_cache_diagnostics_sanitize_data()`:

1. **Query Parameters**:
   - Any sensitive parameter (`password`, `pwd`, `secret`, `token`, `nonce`, `_wpnonce`, `key`, `auth`, `signature`, `donotcachepage`) has its value redacted to `[REDACTED]`.
2. **Cookies**:
   - Cookie values are completely stripped.
   - Only detected cookie names (e.g., `woocommerce_items_in_cart`) are stored.
3. **Private Content**:
   - HTML bodies and responses are never stored in diagnostic logs.
4. **Log Injection Defense**:
   - All diagnostic strings and reasons are sanitized using `sanitize_text_field()` to strip newlines and control characters.

---

## 4. Preload Integration

Preload diagnostics integrate with Phase 8 state accounting (`inc/preload-engine.php`):

1. **URLs Discovered**: Total count of safe public URLs discovered from posts, pages, and XML sitemaps.
2. **URLs Attempted**: Count of URLs for which HTTP GET requests were initiated.
3. **HTTP Requests Successful**: Count of requests that returned HTTP 200 OK.
4. **Valid Cache Artifacts Verified**: Count of URLs where a non-empty static cache file was physically verified on disk following warming.
5. **URLs Skipped**: Count of excluded URLs categorized by reason (`woocommerce_sensitive`, `backend_admin`, `not_cached`, `redirect`, `unsafe_redirect_target`, `invalid_port`, `out_of_path_scope`).
6. **URLs Failed**: Count of network or server failures (connect errors, timeouts, 5xx responses).

An HTTP 200 response alone is never reported as a cache success. It is recorded as `PRELOAD_HTTP_OK` and only promoted to `PRELOAD_CACHED` once `turbo_cache_preload_verify_cache_entry()` returns true.

---

## 5. Storage and API Specifications

### In-Memory vs. Persistent Logging

- **Default State**: Persistent logging is disabled (`turbo_cache_diagnostics_is_enabled() === false`).
- **In-Memory**: The last diagnostic decision for the current request is stored in `$GLOBALS['turbo_cache_last_diagnostic']` and accessible via `turbo_cache_diagnostics_get_current()`.
- **Persistent Ring Buffer**: When enabled, events are appended to `$cache_path . 'diagnostics.json'` with FIFO truncation at 100 entries and max size 256 KB.

### Administrative Endpoints

1. `wpsc_ajax_get_diagnostics`: Returns the diagnostic summary and recent event log. Requires `manage_options` and nonce `wpsc-diagnostics-nonce`.
2. `wpsc_ajax_clear_diagnostics`: Clears the diagnostic log file and resets metrics. Requires `manage_options` and nonce `wpsc-diagnostics-nonce`.
3. `wpsc_ajax_toggle_diagnostics`: Toggles persistent logging on or off. Requires `manage_options` and nonce `wpsc-diagnostics-nonce`.
