# Sizing PHP-FPM

PHP-FPM is the usual runtime for Capell. Sizing is a memory budget, not a CPU count. [Making Capell fast](making-capell-fast.md#tune-a-low-powered-server) gives the starting formula and a 4 GB budget; this page covers measuring the inputs and reading the warning that tells you the pool is too small.

## Measure a worker

Take the resident memory of workers that have served real traffic, including an admin request, not a freshly spawned process:

```bash
ps -C php-fpm8.4 -o rss= | sort -n | tail -n 5
```

The process name varies by distribution (`php-fpm`, `php-fpm8.4`, `php-fpm84`). RSS is in kilobytes. Sample after the site has been warm for a while and take a high percentile, not the average.

As a reference point, one production host (nginx, PHP 8.4, OPcache enabled, a full Capell install) measured about 135 MB per worker. Your figure depends on installed packages, themes, and what the busiest requests do, so measure it rather than copying this one.

## Work out `pm.max_children`

```text
pm.max_children = floor(PHP memory budget / measured worker RSS)
```

The PHP memory budget is what remains after the operating system, database, Redis, queue workers, and headroom. Queue workers are long-lived PHP processes of the same size and come out of the same budget. A worked example for a 4 GB host that gives PHP web workers 1 GB:

| Input               | Value                   |
| ------------------- | ----------------------- |
| PHP web budget      | 1024 MB                 |
| Measured worker RSS | 135 MB                  |
| `pm.max_children`   | `floor(1024 / 135)` = 7 |

Do not round up. A pool that is allowed more children than memory can hold fails by swapping or invoking the OOM killer, which takes the database down with it.

## `ondemand` or `dynamic`

| Manager    | Behaviour                                                    | Use when                                                                          |
| ---------- | ------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| `ondemand` | Spawns workers per request and kills them after an idle time | Small hosts, several pools, and sites where the static cache absorbs most traffic |
| `dynamic`  | Keeps a spare range of idle workers ready                    | A single busy pool where spawn latency on dynamic requests matters                |

With the [static HTML cache](web-server.md) in front, PHP only sees admin, authenticated, and uncached requests, so `ondemand` is normally enough. Pair it with a short `pm.process_idle_timeout` and a `pm.max_requests` of 250 to 1000 so gradual growth is recycled.

```ini
pm = ondemand
pm.max_children = 7
pm.process_idle_timeout = 20s
pm.max_requests = 500
```

## `server reached pm.max_children`

PHP-FPM logs this warning when a request had to wait because every allowed worker was busy:

```text
WARNING: [pool www] server reached pm.max_children setting (7), consider raising it
```

Do not raise the number until you know why the pool filled:

1. **Slow requests holding workers.** Look at what the busy workers are doing (enable `pm.status_path` and the slow log: `request_slowlog_timeout`, `slowlog`). A slow upstream call, an unindexed query, or an export holds a worker for the whole duration.
2. **A burst of uncached traffic.** After a cache purge or deploy, many visitors miss the cache at once. Warm the origin before purging the edge, as described in [refreshing public HTML after a release](deploy-and-cache-refresh.md).
3. **Scanner or bot traffic.** Requests for paths that do not exist still boot Laravel unless nginx answers them. See [hardening basics](deploy-and-cache-refresh.md#scanners-419-and-429-responses).
4. **A genuinely undersized pool.** Raise `pm.max_children` only if the memory budget above has room. If it has not, the fix is more RAM, a cheaper request path, or more caching, not a bigger pool.

A warning that appears briefly after a deploy is expected. A warning that appears during ordinary traffic means the budget or the request path needs work.

## Reload after a release

Reload PHP-FPM after switching releases so every worker boots the new code, particularly when OPcache timestamp validation is off. Under [Octane](octane.md), reload Octane instead.
