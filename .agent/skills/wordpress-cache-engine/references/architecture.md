# Turbo Cache — Architecture

## 1. Purpose

Turbo Cache is a modern WordPress caching plugin derived from the open-source WP Super Cache codebase.

The goal is to evolve the proven WP Super Cache architecture into a modular, maintainable, testable, secure, and performance-focused caching engine while preserving WordPress compatibility and cache correctness.

Turbo Cache must prioritize:

1. Cache correctness
2. WordPress compatibility
3. WooCommerce compatibility
4. Runtime performance
5. Maintainability
6. Security
7. Testability

Aggressive caching must never take precedence over serving correct content.

---

# 2. Architectural Principles

## 2.1 Cache Correctness First

The system must never assume that a request is cacheable simply because it is technically possible to cache it.

Every cache decision must consider, where applicable:

- Authentication state
- User state
- Cookies
- HTTP method
- Query parameters
- Nonces
- REST API requests
- AJAX requests
- WooCommerce sessions
- Cart state
- Checkout state
- Account state
- Personalized content
- Preview mode
- Comment-related states
- Explicit cache exclusions
- Other application-specific dynamic states

A false cache HIT is considered more harmful than a cache MISS.

---

## 2.2 Performance-Critical Path First

The cache engine has two conceptual execution phases.

### Phase 1

Phase 1 runs as early as possible in the WordPress request lifecycle and is responsible for extremely fast cache detection and delivery.

Its responsibilities must remain minimal.

Typical responsibilities:

- Basic request detection
- Minimal cacheability checks
- Cache key determination
- Cache lookup
- Cache HIT delivery
- Early BYPASS/MISS decision

Phase 1 must avoid unnecessary:

- WordPress initialization
- Database queries
- Filesystem scans
- Expensive object creation
- Heavy dependency loading
- Network requests

A cache HIT should be served through the shortest safe execution path available.

### Phase 2

Phase 2 operates after the relevant WordPress environment has been loaded.

Its responsibilities may include:

- Full cache-rule evaluation
- WordPress-aware cacheability checks
- Response generation
- Final cache validation
- Cache storage
- Invalidation
- Diagnostics
- WooCommerce-specific behavior

The architecture must not force the full Phase 2 stack onto requests that can safely be served from cache during Phase 1.

---

# 3. Modular Architecture

The project should use clear logical modules, but must avoid unnecessary abstraction.

The goal is not to convert WP Super Cache into a large framework or an abstraction-heavy OOP system.

Prefer:

- Small focused components
- Clear responsibilities
- Low runtime overhead
- WordPress-native mechanisms where appropriate
- Existing upstream mechanisms when they are proven and suitable

Avoid:

- Speculative abstractions
- Unnecessary interfaces
- Large dependency injection systems
- Framework-style architecture without measurable benefit
- Additional layers that increase execution cost without improving maintainability or correctness

Architecture must follow the actual performance characteristics of WordPress and the upstream WP Super Cache implementation.

---

# 4. High-Level Architecture

The conceptual request flow is:

```text
                    WordPress Request
                           │
                           ▼
                 ┌────────────────────┐
                 │      Phase 1       │
                 │ Fast Request Check │
                 └─────────┬──────────┘
                           │
                    ┌──────┴──────┐
                    │             │
                  HIT          MISS/BYPASS
                    │             │
                    ▼             ▼
              Serve Cache    ┌──────────────┐
                             │   Phase 2    │
                             │ WordPress    │
                             └──────┬───────┘
                                    │
                                    ▼
                            Full Cache Rules
                                    │
                         ┌──────────┴──────────┐
                         │                     │
                       CACHE                 BYPASS
                         │                     │
                         ▼                     ▼
                   Generate Response       Serve Response
                         │
                         ▼
                 Final Cache Validation
                         │
                    ┌────┴────┐
                    │         │
                  STORE     DON'T STORE
                    │         │
                    └────┬────┘
                         ▼
                  Serve Response
```

This is a conceptual architecture. The exact implementation must be validated against the actual WP Super Cache lifecycle.

---

# 5. Cache Delivery Modes

Turbo Cache should support two conceptual cache delivery strategies.

## 5.1 PHP / Drop-in Mode

The PHP-based delivery path is the primary development and behavioral reference for the initial implementation.

This mode provides the greatest control over:

- Cache decisions
- Diagnostics
- Bypass reasons
- Dynamic request detection
- WooCommerce compatibility
- Cache metadata

It is also useful during development and testing because the cache decision process remains observable.

---

## 5.2 Web Server Rewrite Mode

The architecture should preserve support for direct static cache delivery through web-server rules.

Potential environments include:

- Apache
- Nginx

The rewrite-based mode should eventually serve already-generated cache files without unnecessarily executing PHP.

However, it must not implement an independent cache-generation logic.

The same underlying cache rules and storage model should remain the source of truth wherever technically practical.

### Development priority

The initial implementation should prioritize PHP / Drop-in behavior.

Rewrite-based delivery should be implemented and optimized after the core cache engine and invalidation behavior are stable and tested.

---

# 6. Core Components

The architecture consists of the following logical components.

## 6.1 Bootstrap

Responsible for:

- Plugin initialization
- Environment detection
- Loading required components
- Registering WordPress hooks
- Loading configuration
- Detecting incompatible environments

Bootstrap must remain lightweight.

It must not perform expensive operations on every request.

---

## 6.2 Request Context

The Request Context component provides normalized information about the current request.

Potential information includes:

- HTTP method
- URI
- Path
- Query parameters
- Host
- Authentication state
- Relevant cookies
- REST/AJAX state
- Preview state
- WooCommerce state
- Cache bypass signals

The component must not become a heavyweight abstraction on the Phase 1 hot path.

Phase 1 should use only the minimum information required to make a safe early decision.

Full request context can be constructed later when Phase 2 requires it.

---

# 7. Cache Rules Engine

The Cache Rules Engine determines whether a request can safely use or create a page cache entry.

Conceptually:

```text
Request
   │
   ▼
Cache Rules
   │
   ├── CACHE
   │
   └── BYPASS
```

Rules may consider:

- HTTP method
- Authentication
- Cookies
- Query parameters
- URI/path
- WordPress state
- REST
- AJAX
- WooCommerce state
- Explicit exclusions
- Plugin/theme-generated bypass signals

Whenever practical, the engine should provide a reason for a BYPASS decision.

Examples:

```text
BYPASS
Reason: authenticated_user
```

```text
BYPASS
Reason: woocommerce_cart
```

The Rules Engine must not require heavyweight processing when a safe early decision can be made during Phase 1.

---

# 8. Cache Key

Every cache entry must have a deterministic cache key.

The key may depend on:

- Scheme
- Host
- Path
- Relevant query parameters
- Cache variant
- Mobile/desktop state when enabled
- Other explicitly configured cache dimensions

The system must avoid unnecessary cache fragmentation.

At the same time, requests capable of producing different content must never incorrectly share a cache entry.

---

# 9. Cache Storage

The initial cache storage implementation should remain filesystem-based and compatible with the proven WP Super Cache model wherever practical.

Conceptually:

```text
Cache Engine
      │
      ▼
Filesystem Storage
```

A complex storage abstraction or multiple storage backends should not be introduced unless there is a demonstrated architectural need.

Potential storage responsibilities:

- Cache file creation
- Safe cache reads
- Atomic writes
- Cache metadata
- Expiration
- Garbage collection
- Corruption handling where practical

Future storage backends may be considered later if real requirements justify them.

---

# 10. Cache Lifecycle

The general lifecycle is:

```text
REQUEST
   │
   ▼
Phase 1
   │
   ├── HIT ───────────────► Serve Cache
   │
   └── MISS / BYPASS
            │
            ▼
         Phase 2
            │
            ▼
      Full Cache Rules
            │
       ┌────┴────┐
       │         │
     BYPASS    CACHE
       │         │
       ▼         ▼
   WordPress   Generate
   Response    Response
                 │
                 ▼
         Final Cache Validation
                 │
           ┌─────┴─────┐
           │           │
         STORE       NO STORE
           │           │
           └─────┬─────┘
                 ▼
           Serve Response
```

A response must not automatically be cached merely because the initial request passed the cacheability check.

The final response must also satisfy cache safety requirements.

---

# 11. Cache Invalidation

Cache invalidation is a first-class subsystem.

The system should prefer targeted invalidation whenever safely possible.

Examples:

```text
Post Updated
    │
    ├── Purge post cache
    ├── Purge affected archives
    └── Purge related taxonomy cache
```

Potential triggers include:

- Post creation
- Post update
- Post deletion
- Page changes
- Category changes
- Tag changes
- Menu changes
- Widget changes
- Theme changes
- Relevant plugin changes
- WordPress settings changes
- WooCommerce product changes
- Product price changes
- Product stock changes
- Product visibility changes
- Product/category changes

Full cache purge remains available as a safe fallback.

---

# 12. Cache Purge

The purge subsystem should support multiple levels.

```text
PURGE
├── Single URL
├── Related URLs
├── Post
├── Product
├── Taxonomy
├── Site section
└── Full cache
```

Purge operations must be predictable and observable.

The system should avoid unnecessary full-cache deletion when precise invalidation is safe.

---

# 13. Preload Engine

Preload must reuse the same cache-generation and cache-safety logic as normal requests wherever practical.

There must not be a second independent caching implementation.

Conceptually:

```text
Preload URL
     │
     ▼
Request Context
     │
     ▼
Cache Rules
     │
     ▼
Cache Lookup
     │
     ▼
Generate Response
     │
     ▼
Final Validation
     │
     ▼
Store Cache
```

The initial preload implementation may use real HTTP requests so that it exercises behavior close to a real visitor request.

The preload engine must control:

- Batch size
- Delay
- Concurrency
- Maximum workload
- Retry behavior
- Failure handling
- Progress
- Cancellation

Preload must not create excessive server load.

If later performance testing demonstrates that another execution strategy is necessary, that strategy must still reuse the same cache rules and validation logic.

---

# 14. Cache Garbage Collection

Garbage collection is responsible for safely removing expired or obsolete cache data.

Responsibilities include:

- Expiration detection
- Old cache cleanup
- Orphan cleanup where applicable
- Storage health checks
- Protection against deleting active cache entries

Garbage collection must not require expensive full-cache scans on every request.

Background or scheduled execution should be preferred.

---

# 15. WordPress Integration

WordPress-specific behavior should be isolated from generic cache-engine logic where practical.

Integration areas include:

- WordPress hooks
- Rewrite rules
- WP-Cron
- REST API
- AJAX
- Authentication
- Login
- Preview
- Comments
- Feeds
- Search
- Content updates
- Theme/plugin changes
- Multisite

The cache engine should not become unnecessarily coupled to individual WordPress features.

---

# 16. WooCommerce Integration

WooCommerce is a first-class integration target from the beginning of the project.

The architecture must explicitly understand dynamic WooCommerce states.

At minimum:

- Cart
- Checkout
- My Account
- Order confirmation
- WooCommerce sessions
- Cart/session cookies
- AJAX
- WooCommerce Blocks
- Product updates
- Product price changes
- Product stock changes
- Product visibility changes
- Product/category changes

The system must never serve cached personalized cart, checkout, account, or session content.

WooCommerce invalidation should be as precise as safely possible.

---

# 17. HTTP and Browser Cache

HTTP/browser caching is separate from server-side page-cache generation.

Potential responsibilities include:

- Cache-Control
- Expires
- ETag where appropriate
- Last-Modified where appropriate
- Vary
- Browser caching
- Static asset caching

Browser cache behavior must not be tightly coupled to page-cache generation.

---

# 18. Asset Optimization

Asset optimization is intentionally outside the initial core implementation.

Potential future modules include:

```text
Asset Optimization
├── CSS optimization
├── JavaScript optimization
├── Defer
├── Delay
├── Minification
├── Lazy loading
├── Font optimization
└── HTML optimization
```

These features must not be implemented as part of the initial page-cache core.

They may be introduced in later phases after:

- Page caching is stable
- Cache invalidation is reliable
- WooCommerce behavior is validated
- Preload is stable
- Diagnostics are available
- Automated testing exists

Asset optimization must remain modular so it cannot compromise cache correctness.

---

# 19. CDN Integration

CDN functionality should remain an independent integration layer.

Potential responsibilities:

- CDN URL rewriting
- Static asset mapping
- CDN purge integration
- CDN configuration validation

The core cache engine must remain fully functional without a CDN.

No external CDN or service should become a mandatory dependency.

---

# 20. Object Cache Detection

Object caching is separate from full-page caching.

The system may detect:

- Redis
- Memcached
- WordPress object-cache drop-ins

Detection must distinguish between:

```text
PHP extension available
        ≠
Service reachable
        ≠
WordPress object cache active
```

No object-cache service should be considered active based only on extension detection.

---

# 21. Diagnostics and Debugging

Diagnostics are a first-class feature.

The plugin should be able to explain cache behavior.

Examples:

```text
Cache Status: BYPASS
Reason: logged_in_user
```

```text
Cache Status: HIT
Variant: desktop
Age: 42 seconds
```

Diagnostics may expose:

- HIT
- MISS
- BYPASS
- Bypass reason
- Cache key
- Cache age
- Cache file
- Purge events
- Preload status
- Storage status
- Rewrite status
- Configuration problems
- Environment compatibility

Diagnostics must not introduce significant overhead when disabled.

---

# 22. Admin Architecture

The administration interface must remain separate from the runtime cache engine.

Conceptual structure:

```text
Dashboard
├── Overview
├── Cache
│   ├── Page Cache
│   ├── Browser Cache
│   └── Cache Rules
├── Preload
├── WooCommerce
├── CDN
├── Object Cache
├── Diagnostics
├── Tools
└── Settings
```

The exact UI may evolve.

Admin UI code must not contain core cache-engine logic.

---

# 23. Configuration

Configuration must have a single authoritative source.

Components must not maintain conflicting copies of configuration values.

Configuration must be:

- Validated
- Sanitized
- Predictable
- Efficient
- Backward-compatible where practical

Settings that affect cached content must trigger appropriate invalidation.

---

# 24. Logging

Logging must be:

- Optional
- Controlled
- Lightweight
- Useful for diagnostics
- Safe for production

Verbose logging must never be continuously enabled by default.

Sensitive information must not be logged unnecessarily.

---

# 25. Security

Security boundaries must be enforced throughout the architecture.

Important areas include:

- Capability checks
- Nonce validation
- Input sanitization
- Output escaping
- File access protection
- Cache file protection
- Path traversal prevention
- Configuration validation
- Admin endpoint protection
- AJAX/REST authorization

Cache files must never unintentionally expose sensitive WordPress or WooCommerce data.

---

# 26. Performance Requirements

Performance is a primary architectural constraint.

The plugin must minimize:

- Database queries
- Filesystem operations
- Expensive hooks
- Repeated configuration parsing
- Repeated environment detection
- Network requests
- Large in-memory operations
- Object creation on hot paths

Expensive operations should be:

- Cached
- Deferred
- Scheduled
- Executed only when necessary

The cache plugin itself must not become a performance problem.

---

# 27. Testing Architecture

Testing must exist at multiple levels:

```text
Unit Tests
    │
    ▼
Integration Tests
    │
    ▼
WordPress Tests
    │
    ▼
WooCommerce Tests
    │
    ▼
Browser / Playwright Tests
    │
    ▼
Performance Tests
```

Important scenarios include:

- Cache HIT
- Cache MISS
- Cache BYPASS
- Logged-in users
- Cookies
- Query parameters
- REST
- AJAX
- POST requests
- WooCommerce cart
- Checkout
- My Account
- Product updates
- Cache invalidation
- Preload
- Mobile/desktop variants
- Multisite where supported

No feature should be considered complete based solely on static code inspection.

---

# 28. Observability

The system should make it possible to answer:

```text
Why was this request cached?
Why was this request not cached?
Which cache entry was used?
When was it generated?
Why was it purged?
Why was it regenerated?
```

Observability must be designed without adding significant overhead to normal production requests.

---

# 29. Dependency Boundaries

Modules should communicate through clearly defined responsibilities.

Prefer:

```text
Admin UI
    ↓
Cache Service
    ↓
Storage
```

over:

```text
Admin UI
    ↓
Direct filesystem manipulation
```

Similarly:

```text
WooCommerce Hook
    ↓
Invalidation Service
    ↓
Cache Purge
```

rather than WooCommerce hooks directly manipulating cache files.

However, these boundaries must remain lightweight and must not introduce unnecessary abstraction layers.

---

# 30. Upstream Compatibility

WP Super Cache is the upstream architectural reference and starting point.

The project should preserve useful upstream behavior where practical while allowing architectural improvements.

Changes must be evaluated against:

- Existing behavior
- Compatibility
- Performance
- Security
- Maintainability
- Test coverage

The project must preserve applicable GPL and copyright notices.

Proprietary implementations from other caching plugins must not be copied or incorporated.

The project must avoid unnecessary divergence from upstream where existing behavior is proven, efficient, and compatible with project goals.

---

# 31. Development Priority

The initial development priority is intentionally narrow.

### Initial Core

```text
1. Page Cache
2. Request / Cache Rules
3. Cache Storage
4. Cache HIT / MISS / BYPASS
5. Cache Invalidation
6. Preload
7. WooCommerce Compatibility
8. Diagnostics
9. Browser Cache
10. Testing Infrastructure
```

### Later Modules

```text
11. Rewrite / Expert Delivery
12. CDN
13. Object Cache integrations
14. Advanced Cache Rules
15. Advanced Analytics
16. Asset Optimization
```

This ordering may change after the WP Super Cache audit if the upstream architecture reveals a better or safer implementation path.

---

# 32. Architectural Decision Rule

When choosing between two implementations:

1. Prefer correctness over aggressiveness.
2. Prefer a simpler architecture over unnecessary abstraction.
3. Prefer lower runtime overhead.
4. Prefer WordPress-native mechanisms where appropriate.
5. Prefer proven upstream behavior when it does not conflict with project goals.
6. Prefer measurable behavior over assumptions.
7. Prefer testable implementations.
8. Prefer reversible architectural changes.
9. Never introduce a dependency without a clear benefit.
10. Never optimize without evidence when the optimization increases complexity.
11. Do not rewrite upstream code merely for stylistic reasons.
12. Do not introduce OOP or abstraction solely because it appears architecturally cleaner.
13. Performance-critical paths must be evaluated based on actual execution cost.

---

# 33. Upstream Audit Requirement

Before major implementation or refactoring, the actual WP Super Cache codebase must be inspected.

The audit must identify:

- File structure
- Bootstrap process
- `advanced-cache.php`
- Phase 1 execution
- Phase 2 execution
- Cache lookup
- Cache generation
- Cache storage
- Cache invalidation
- Preload
- Garbage collection
- Simple mode
- Expert/rewrite mode
- Admin architecture
- WordPress hooks
- Global state
- Dependencies
- Performance-critical paths
- WooCommerce-related behavior
- Existing tests
- Potential risks

The audit must classify components into:

```text
REUSE
REFACTOR
REPLACE
REMOVE
NEW
```

No major refactor should begin before this mapping exists.

---

# 34. Current Architectural Status

This document defines the current target architecture and development priorities.

It is not considered the final implementation architecture.

The architecture must be updated after the WP Super Cache upstream audit.

The upstream audit has priority over speculative implementation decisions.

No major implementation decision should be considered final until the relevant upstream behavior has been inspected and, where necessary, tested.
