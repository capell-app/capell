# Capell HasCache Guide

![Capell HasCache Guide screenshot](./images/screenshots/core-settings-backed-configuration.png)

This guide explains how to use Capell's HasCache trait for caching in your services, models, or actions. It covers all HasCache methods, configuration, and advanced features like bypassing cache for specific keys.

---

## 1. Overview

The `HasCache` trait provides a consistent, testable, and feature-rich caching API for Capell packages. It wraps Laravel's cache system but adds:

- Tag support
- Null sentinels (to distinguish missing from null values)
- Configurable TTL
- Key-based cache bypassing
- Test-friendly helpers

You should use HasCache in your domain logic instead of calling Laravel's Cache facade directly.

---

## 2. Basic Usage

Add the trait to your service or model:

```php
use Capell\Core\Concerns\HasCache;

class MyService {
    use HasCache;

    public function getExpensiveResult(string $id): mixed
    {
        return $this->rememberCache(
            'expensive-result-' . $id,
            fn () => $this->computeResult($id),
            600 // cache for 10 minutes
        );
    }
}
```

---

## 3. HasCache Methods

### rememberCache

Retrieve or store a value in cache. If the key is missing, computes and stores the value.

```php
$result = $this->rememberCache('my-key', fn () => $this->expensive(), 300);
```

### getFromCache

Get a value from cache, or null if not found or sentinel.

```php
$value = $this->getFromCache('my-key');
```

### setToCache

Store a value in cache with optional TTL.

```php
$this->setToCache('my-key', $value, 1200); // 20 minutes
```

### cacheExists

Check if a cache key exists and is not the sentinel.

```php
if ($this->cacheExists('my-key')) { ... }
```

### removeCacheKey

Remove a specific cache key.

```php
$this->removeCacheKey('my-key');
```

### flushCache

Clear all cache entries for the configured tag/store.

```php
$this->flushCache();
```

---

## 4. Advanced: TTL, Sentinels, and Tags

- TTL can be an int (seconds), DateTimeInterface, DateInterval, or Closure returning one.
- Null values are stored as a sentinel string (`__capell_null__`) so you can distinguish between missing and null.
- If your cache driver supports tags, HasCache will use them (default tag: `capell-app`).

---

## 5. Bypassing Cache for Specific Keys

HasCache respects the `disable_cache_save_keys` config. If a key matches any pattern, it will not be saved to cache (but will still compute and return the value).

**Configure in `config/capell.php`:**

```php
'disable_cache_save_keys' => ['page-*', '/^user-\\d+$/', 'my-key'],
```

- Exact: `'my-key'`
- Wildcard: `'page-*'`
- Regex: `'/^user-\\d+$/'`

Or in `.env`:

```
CAPELL_DISABLE_CACHE_SAVE_KEYS=page-*,/user-.*$/,my-key
```

---

## 6. Example: Using and Bypassing HasCache

```php
use Capell\Core\Concerns\HasCache;

class DemoService {
    use HasCache;

    public function getUserData(string $userId): array
    {
        return $this->rememberCache(
            'user-' . $userId,
            fn () => $this->fetchUserData($userId),
            900
        );
    }
}
```

If `disable_cache_save_keys` includes `/^user-\d+$/`, then `user-123` will never be cached.

---

## 7. Example Pest Test: Verifying Bypass

```php
test('HasCache respects disable_cache_save_keys', function () {
    config(['capell-core.disable_cache_save_keys' => ['page-*', '/^user-\\d+$/', 'my-key']]);
    $service = new class {
        use \Capell\Core\Concerns\HasCache;
        public function get(string $key) {
            return $this->rememberCache($key, fn () => uniqid('val', true), 600);
        }
    };

    $excluded = ['page-123', 'user-456', 'my-key'];
    $included = ['allowed-key'];

    foreach ($excluded as $key) {
        $value = $service->get($key);
        expect($this->app['cache']->get($key))->toBeNull();
        expect($value)->not()->toBeNull();
    }
    foreach ($included as $key) {
        $value = $service->get($key);
        expect($this->app['cache']->get($key))->toBe($value);
    }
});
```

---

## 8. Further Reading

- [Frontend guide](../../../docs/frontend/guide.md)
- [Frontend server config](../../frontend/docs/server-config.md)
