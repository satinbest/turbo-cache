گزارش Upstream Audit که همین الان تهیه کردی را مبنا قرار بده.

مرحله بعد فقط **مستندسازی Upstream** است.

هیچ کد پروژه Turbo Cache را تغییر نده.
هیچ Refactor انجام نده.
هیچ Feature جدیدی اضافه نکن.
هیچ فایل upstream را حذف یا اصلاح نکن.

فقط یک فایل جدید ایجاد کن:

`.agent/skills/wordpress-cache-engine/references/upstream.md`

هدف این فایل این است که به عنوان مرجع فنی رسمی Turbo Cache برای شناخت WP Super Cache 3.1.4 استفاده شود.

محتوای `upstream.md` را بر اساس implementation واقعی سورس WP Super Cache 3.1.4 تهیه کن و این بخش‌ها را داشته باش:

1. Upstream Identity

- Version
- License
- Repository
- Major architectural characteristics

2. Runtime Bootstrap

- advanced-cache.php
- Phase 1
- WordPress plugin bootstrap
- Phase 2
- ترتیب واقعی load شدن فایل‌ها

3. Phase 1

- تمام bypass checks مهم
- cache lookup
- cache serve
- شرایط HIT
- شرایط MISS
- شرایط BYPASS
- وابستگی‌های Phase 1
- مسیر hot path

4. Phase 2

- wp_cache_phase2()
- output buffering
- cache generation
- validation
- storage
- hooks
- shutdown behavior

5. Cache Storage

- directory layout
- filename rules
- gzip files
- metadata
- temporary files
- atomic rename
- permissions

6. Cache Key / URL Normalization

- نحوه تبدیل URL به cache path/name
- query strings
- HTTPS
- host
- tracking parameters
- multisite

7. Cache Invalidation

- تمام hookهای اصلی
- post invalidation
- archive invalidation
- comment invalidation
- menu invalidation
- full purge
- targeted purge

8. Preload

- cron
- queue/state
- HTTP requests
- rate limiting
- retry/error behavior
- limitations

9. Garbage Collection

- scheduling
- expiration
- recursive traversal
- concurrency protection

10. Simple vs Expert

- تفاوت دقیق
- مسیر execution
- Apache rewrite
- PHP/drop-in
- limitations
- Nginx implications

11. Configuration

- wp-cache-config.php
- how settings are persisted
- global variables
- configuration risks

12. Admin / REST

- admin architecture
- settings pages
- REST endpoints
- interaction with runtime

13. WooCommerce

- دقیقاً چه چیزی در upstream وجود دارد
- چه چیزی وجود ندارد
- cookie handling
- cart/checkout/my-account behavior
- هر نتیجه باید بر اساس implementation واقعی باشد

14. Tests

- smoke
- integration
- E2E
- what each test layer actually covers

15. Global State

- مهم‌ترین globals
- نقش هرکدام
- وابستگی‌های بین آنها

16. Technical Debt

- legacy procedural architecture
- large files
- configuration mutation
- locking
- preload limitations
- rewrite limitations
- other evidence-based issues

17. Turbo Cache Fork Strategy
    برای هر بخش یکی از این وضعیت‌ها را مشخص کن:

REUSE
REFACTOR
REPLACE
REMOVE
NEW

اما فعلاً هیچ‌کدام را اجرا نکن.

18. Upstream Invariants

یک بخش بسیار مهم اضافه کن با عنوان:

`Upstream Invariants`

در آن مشخص کن چه رفتارهایی از WP Super Cache را در اولین نسخه Turbo Cache نباید بدون تست و دلیل تغییر دهیم.

مثلاً:

- Phase 1 باید سبک باقی بماند.
- Cache HIT نباید WordPress را کامل load کند.
- Cache storage باید atomic باشد.
- Cache correctness بر aggressive caching اولویت دارد.
- Cache response ناقص نباید ذخیره شود.
- Logged-in / personalized requests نباید اشتباهاً cache شوند.

این بخش را بر اساس implementation واقعی کامل کن.

19. Baseline Rule

در پایان این قانون را ثبت کن:

Turbo Cache ابتدا باید یک Baseline قابل اجرا و قابل تست از WP Super Cache 3.1.4 داشته باشد. هر تغییر معماری یا رفتاری باید پس از ثبت رفتار فعلی upstream و ایجاد تست مناسب انجام شود.

مهم:

- تعداد خطوط فایل‌ها را حدس نزن.
- نام تابع یا فایل را فقط وقتی ذکر کن که implementation آن را بررسی کرده‌ای.
- اگر چیزی نامشخص است، صریحاً بنویس `Unknown / Requires Verification`.
- هیچ ادعایی را فقط از روی نام فایل نتیجه‌گیری نکن.
- این سند باید Reference فنی باشد، نه پیشنهاد معماری جدید.
