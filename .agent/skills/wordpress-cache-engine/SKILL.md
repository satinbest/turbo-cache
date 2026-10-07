---
name: wordpress-cache-engine
description: >
  Development skill for a modern WordPress caching plugin based on the
  open-source WP Super Cache codebase. Use this skill when working on
  WordPress page caching, cache generation, cache invalidation, cache
  expiration, cache preload, cache rules, WooCommerce compatibility,
  performance optimization, cache debugging, testing, security, or
  upstream maintenance for this project.
---

# WordPress Cache Engine Development Skill

This skill defines the persistent development rules and engineering principles
for this project.

## Project Identity

This project is a modern WordPress caching plugin derived from the
open-source WP Super Cache project.

The project must remain independent from proprietary caching plugins.

## Core Principles

- Performance is a first-class requirement.
- Cache correctness is more important than aggressive caching.
- Security is a first-class requirement.
- Compatibility with WordPress is mandatory.
- WooCommerce compatibility is a first-class requirement.
- Do not introduce unnecessary external dependencies.
- Do not introduce unnecessary network requests.
- Do not add telemetry or tracking unless explicitly approved.
- Do not add advertisements, upsells, or promotional integrations.
- Never claim that a feature works without actually testing it.
- Do not remove or modify code without understanding its dependencies.
- Preserve required GPL and upstream copyright notices.

## Proprietary Code

Do not copy, reproduce, reverse-engineer, or incorporate proprietary code
from WP Rocket or other proprietary plugins.

The project may implement similar functionality independently when that
functionality is technically and legally appropriate.

## Development Discipline

Before modifying existing functionality:

1. Understand the current implementation.
2. Identify dependencies.
3. Identify affected WordPress hooks and execution paths.
4. Check existing tests.
5. Make the smallest appropriate change.
6. Run relevant tests.
7. Verify that unrelated functionality has not regressed.

## Testing

A feature is not considered complete until it has been tested.

Testing should include the appropriate combination of:

- PHP tests
- WordPress integration tests
- Browser tests
- Playwright end-to-end tests
- Performance tests
- Cache HIT/MISS verification
- WooCommerce scenarios

Never report a test as successful if it was not actually executed.

## Cache Safety

Never cache a request simply because it appears technically cacheable.

Always consider:

- Authentication state
- Cookies
- POST requests
- Query parameters
- WooCommerce sessions
- Cart state
- Checkout state
- My Account state
- REST API requests
- Nonces
- Personalized content
- Cache invalidation

A false cache HIT can be more harmful than a cache MISS.

## Performance

The caching plugin itself must introduce minimal overhead.

Avoid:

- unnecessary database queries
- unnecessary filesystem operations
- unnecessary WordPress hooks
- expensive operations on every request
- unnecessary network requests

Performance-sensitive code must be benchmarked when appropriate.

## Upstream

The original WP Super Cache project is an important upstream reference.

Do not unnecessarily diverge from upstream behavior.

When replacing or substantially modifying upstream functionality:

- document the reason
- preserve compatibility where practical
- keep upstream comparison possible
- document significant architectural decisions

## Documentation

Important architectural decisions must be documented.

Do not create large amounts of documentation for trivial changes.

Prefer concise, useful documentation that explains:

- why a decision was made
- what behavior is expected
- compatibility implications
- testing requirements

## Scope

This skill applies to development of this WordPress caching project.

Do not apply project-specific assumptions to unrelated projects.
