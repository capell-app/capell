# Capell queued work reliability standard

This standard governs every class that implements `Illuminate\Contracts\Queue\ShouldQueue` in the Capell host monorepo and in the companion `capell-packages` repository. It exists because Laravel's defaults are not safe defaults for a CMS: a queued class that declares nothing gets **one** attempt, **no** backoff, **no** timeout, **no** deduplication, and **no** failure handler. One transient database blip, one rate-limited API response, or one restarted worker permanently drops the work, and nothing in the product tells anyone it happened.

The rules below are enforced mechanically by `composer check:queue-contract` in both repositories. Read [Coding standards](coding-standards.md) first; this document adds constraints to it and never weakens them.

## Scope

| In scope                                                                                         | Out of scope                                                                                             |
| ------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| Jobs under `packages/*/src/Jobs/`                                                                | Queued notifications under `packages/*/src/Notifications/`                                               |
| Queued listeners under `packages/*/src/Listeners/`                                               | Queued mailables under `packages/*/src/Mail/`                                                            |
| Any other `ShouldQueue` class whose name ends in `Job`                                           | Abstract base classes — the concrete subclass owns the contract                                           |

Notifications and mailables are queued work too, but their attempt budget, transport retry, and failure reporting are owned by the mail/notification channel rather than by the class itself, and Capell does not currently attach per-notification recovery. They are deliberately excluded from the gate so the reported debt stays actionable. Extending the contract to cover them is a separate, deliberate change to this document and to both `check-queue-contract.php` scripts.

## The rules

### QUEUE001 — declare a retry budget

Every in-scope class declares one of:

- `public int $tries` with a value of `2` or more;
- `public int $tries = 0` when the work must keep retrying until `retryUntil()` or an operator stops it, and the reason is stated in a comment;
- a `tries(): int` method reading a config key;
- a `retryUntil(): DateTimeInterface` method, which supersedes the attempt count with a wall-clock deadline.

`public int $tries = 1` is a violation, not a declaration. If a single attempt is genuinely correct — see the non-idempotent carve-out below — record it as an exemption with its reason rather than as a silent `1`.

`packages/marketplace/src/Jobs/RunMarketplaceInstallAttemptJob.php` is the reference for the deadline-bounded form: `public int $tries = 0` with `retryUntil()` and `public int $maxExceptions = 3`, so an install waiting on a lock holder keeps retrying inside the window while a reproducibly throwing install still gives up after three exceptions. Its comment states why an attempt cap would be wrong there — an unlimited budget without a stated reason is not acceptable.

### QUEUE002 — pair retries with backoff

A class that attempts work more than once declares `public array $backoff`, `public int $backoff`, a `backoff()` method, or `retryUntil()`. Retrying immediately turns one failing dependency into a self-inflicted denial of service against that dependency.

Use an escalating list rather than a single interval whenever the failure could be a rate limit or a cold external service:

```php
/** @var list<int> */
public array $backoff = [10, 60, 300, 900];
```

That is the shape used by `packages/payments/src/Jobs/ProcessPayPalWebhookEventJob.php` in the packages repository: five attempts spread over roughly twenty minutes, which survives a PayPal outage without giving up and without hammering it.

### QUEUE003 — implement a terminal failure handler

Every in-scope class implements:

```php
public function failed(?Throwable $exception): void
```

Without it, exhausting the retry budget writes a row to `failed_jobs` and nothing else. The handler owns the operational consequence of permanent failure: marking the domain record failed, releasing a lock or claim, emitting the structured log, and notifying whoever is waiting. It must be safe to run when the job never started successfully, so it may not assume any partial state.

`packages/marketplace/src/Jobs/RunMarketplaceInstallAttemptJob.php` is the reference: it records the terminal state against the install operation so the dashboard shows a failed install rather than an install that appears to still be running.

Do not log-and-rethrow from `failed()`. The queue worker already owns the exception; the handler owns the domain and product consequences.

### QUEUE004 — deduplicate queued listeners

A queued listener that reacts to a model lifecycle event fires **once per saved model**. A bulk edit of two hundred pages therefore enqueues two hundred identical jobs, each doing the same expensive whole-site work. Every queued listener under `src/Listeners/` must therefore either:

- implement `Illuminate\Contracts\Queue\ShouldBeUnique` **and** a `uniqueId(): string` keyed on the smallest unit of work that is actually distinct — normally the site, not the model; or
- apply `Illuminate\Queue\Middleware\WithoutOverlapping` through `middleware()`; or
- debounce upstream so the listener enqueues one coalesced job instead of one job per save.

`ShouldBeUnique` without `uniqueId()` is not a declaration: Laravel then keys uniqueness on the serialized class and its properties, which is exactly the per-model granularity that caused the problem.

The reference implementations both live in the packages repository:

- `packages/site-discovery/src/Actions/RequestSiteSitemapRegenerationAction.php` applies a config-driven debounce so many saves collapse into one regeneration request.
- `packages/site-discovery/src/Actions/GenerateSitemapAction.php` combines a `Cache::lock` with `WithoutOverlapping`, so even a racing dispatch cannot produce two concurrent writers of the same sitemap.

Prefer the debounce for "the world changed, rebuild the derived artefact" work, and prefer uniqueness or overlap protection for "this specific record needs processing" work.

### QUEUE005 — bound outbound calls with a timeout

A class whose handler reaches an external service — `Http::`, `Process::`, `proc_open`, `shell_exec`, `curl_init`, or a remote `file_get_contents` — declares `public int $timeout`. Without one, a hung connection holds a worker slot until the process is killed, and a small worker pool stalls the entire queue behind a single unresponsive third party.

Set the timeout below the queue's `retry_after` so the worker reclaims the job rather than running it twice concurrently.

`packages/frontend-optimizer/src/Jobs/GenerateCriticalCssJob.php` in the packages repository is the fullest reference in either repository: a configurable queue, `$tries`, `$backoff`, `$timeout`, `retryUntil()`, and `WithoutOverlapping` on a single long-running external tool invocation.

## Carve-out: non-idempotent external writes must not be blindly retried

Retry is only safe when repeating the work is harmless. A job that captures a payment, refunds a customer, sends an outbound message, or otherwise creates state in a third-party system that cannot be recreated identically must not simply raise `$tries` and hope.

Such a job must do one of the following before it is allowed a retry budget above one attempt:

1. **Make the external call idempotent.** Pass an idempotency key the provider honours, so a repeated attempt returns the original result instead of performing the action twice.
2. **Split the job.** Persist the intent, then perform the single non-idempotent call in a step that records its own completion before returning, so a retry resumes after the call rather than repeating it.
3. **Retry the processing, not the write.** Queue the received event and retry only the local processing of it.

The `payments` package in the packages repository is the reference for option three. `packages/payments/src/Jobs/ProcessPayPalWebhookEventJob.php` and `packages/payments/src/Jobs/ProcessStripeWebhookEventJob.php` do not retry an outbound charge; they retry the local processing of an already-received, already-persisted webhook event, keyed on that stored event's identifier. Re-running them re-reads the same stored payload and converges on the same local state, which is why five attempts with escalating backoff is correct there and would be dangerous on a job that called the payment provider's capture endpoint directly.

When none of the three options is available, declare a single attempt as an explicit exemption and give the reason:

```php
/**
 * @queue-contract-exempt QUEUE001 A repeat attempt would issue a second provider-side transfer; the operator retries manually from the dashboard.
 */
```

An exemption identifies a real constraint. "The gate complained" and "this was quicker" are not reasons.

## Enforcement

Both repositories ship `scripts/check-queue-contract.php`:

| Command                                                                   | Effect                                                                        |
| ------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `composer check:queue-contract`                                            | Verify the tree against the recorded baseline. Exit `2` on a new violation.    |
| `composer check:queue-contract -- --format=json`                           | The same check with machine-readable output.                                   |
| `composer check:queue-contract -- --strict`                                | Also fail when a baselined violation has been fixed but not yet removed.       |
| `composer check:queue-contract -- --update`                                | Rewrite `scripts/queue-contract-baseline.json` from the current tree.          |
| `composer check:queue-contract -- --root=packages/site-discovery`          | Narrow the scan to one package while iterating.                                |

In the packages repository, prefix these with `COMPOSER=composer.local.json`.

The gate runs in the full preflight stage list of both repositories.

### The baseline is a debt ledger

`scripts/queue-contract-baseline.json` records the violations that existed when this standard was introduced, keyed as `<path>::<rule>`. The gate reports them as known debt and fails only on identifiers that are not in the baseline. The rules are the same for old and new code; the baseline only controls when the build breaks.

- Never run `--update` to absorb a violation you just introduced. Fix the class, or declare an exemption with its reason.
- When you fix a baselined class, run `--update` in the same change so the baseline shrinks. `--strict` reports these stale entries.
- Adding a rule to this standard is the one legitimate reason for the baseline to grow, and it happens in the same change that adds the rule.
