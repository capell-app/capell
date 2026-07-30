<?php

declare(strict_types=1);

namespace Capell\Core\Support\Http;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Reusable outbound HTTP retry policy for Capell packages.
 *
 * Applies a bounded number of attempts with a fixed fallback delay, and honours a
 * `Retry-After` response header on 429 and 503 responses in both supported formats:
 * a delay in seconds (`Retry-After: 30`) and an HTTP date (`Retry-After: Wed, 21 Oct
 * 2026 07:28:00 GMT`). The honoured delay is always clamped to a configured maximum
 * so a hostile or mistaken upstream cannot stall a worker indefinitely.
 *
 * Retries never convert a failed request into an exception (`throw: false`); the
 * caller still inspects the final response exactly as it would without retries.
 *
 * Only apply this to genuinely idempotent operations. Retrying a non-idempotent
 * write (a payment capture, a single-use code exchange, a job-creating mutation)
 * risks duplicating the effect and is a defect rather than an improvement.
 *
 * Config contract — `fromConfig('some-package.http')` reads these keys, each falling
 * back to the default passed by the calling package when absent or non-numeric:
 *
 * - `some-package.http.retry_times`         total attempts, floored at 1
 * - `some-package.http.retry_delay_ms`      fallback delay between attempts
 * - `some-package.http.retry_after_max_ms`  ceiling applied to an honoured `Retry-After`
 *
 * Packages that resolve their own settings (injected arrays, settings classes) should
 * use `make()` and pass the resolved integers directly.
 */
final class OutboundHttpRetry
{
    public const int DEFAULT_RETRY_TIMES = 3;

    public const int DEFAULT_RETRY_DELAY_MS = 500;

    public const int DEFAULT_RETRY_AFTER_MAX_MS = 60000;

    /** @var list<int> */
    private const array RETRY_AFTER_STATUSES = [429, 503];

    private function __construct(
        private readonly int $retryTimes,
        private readonly int $retryDelayMs,
        private readonly int $retryAfterMaxMs,
    ) {}

    public static function make(
        int $retryTimes = self::DEFAULT_RETRY_TIMES,
        int $retryDelayMs = self::DEFAULT_RETRY_DELAY_MS,
        int $retryAfterMaxMs = self::DEFAULT_RETRY_AFTER_MAX_MS,
    ): self {
        return new self(
            retryTimes: max(1, $retryTimes),
            retryDelayMs: max(0, $retryDelayMs),
            retryAfterMaxMs: max(0, $retryAfterMaxMs),
        );
    }

    /**
     * Build the policy from a package config prefix, e.g. `capell-newsletter.http`.
     */
    public static function fromConfig(
        string $configPrefix,
        int $retryTimes = self::DEFAULT_RETRY_TIMES,
        int $retryDelayMs = self::DEFAULT_RETRY_DELAY_MS,
        int $retryAfterMaxMs = self::DEFAULT_RETRY_AFTER_MAX_MS,
    ): self {
        $configPrefix = rtrim($configPrefix, '.');

        return self::make(
            retryTimes: self::integerConfig($configPrefix . '.retry_times', $retryTimes),
            retryDelayMs: self::integerConfig($configPrefix . '.retry_delay_ms', $retryDelayMs),
            retryAfterMaxMs: self::integerConfig($configPrefix . '.retry_after_max_ms', $retryAfterMaxMs),
        );
    }

    public function apply(PendingRequest $request): PendingRequest
    {
        return $request->retry(
            $this->retryTimes,
            $this->delay(...),
            $this->shouldRetry(...),
            throw: false,
        );
    }

    public function retryTimes(): int
    {
        return $this->retryTimes;
    }

    private static function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function delay(int $attempt, ?Throwable $exception): int
    {
        unset($attempt);

        if ($exception instanceof RequestException && in_array($exception->response->status(), self::RETRY_AFTER_STATUSES, true)) {
            return $this->retryAfterDelay($exception) ?? $this->retryDelayMs;
        }

        return $this->retryDelayMs;
    }

    private function shouldRetry(?Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function retryAfterDelay(RequestException $exception): ?int
    {
        $retryAfter = $exception->response->header('Retry-After');

        if (! is_string($retryAfter) || trim($retryAfter) === '') {
            return null;
        }

        $retryAfter = trim($retryAfter);
        $delayMs = ctype_digit($retryAfter)
            ? $this->secondsDelay($retryAfter)
            : $this->dateDelay($retryAfter);

        return min(max(0, $delayMs), $this->retryAfterMaxMs);
    }

    private function secondsDelay(string $retryAfter): int
    {
        $seconds = filter_var($retryAfter, FILTER_VALIDATE_INT);

        if (! is_int($seconds) || $seconds > intdiv(PHP_INT_MAX, 1000)) {
            return $this->retryAfterMaxMs;
        }

        return $seconds * 1000;
    }

    private function dateDelay(string $retryAfter): int
    {
        try {
            return (int) max(0, CarbonImmutable::now()->diffInMilliseconds(CarbonImmutable::parse($retryAfter), false));
        } catch (Throwable) {
            return $this->retryDelayMs;
        }
    }
}
