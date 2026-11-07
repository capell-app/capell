<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Closure;
use InvalidArgumentException;

/**
 * The steps that withdraw stored public output when public-read eligibility
 * changes: each package that keeps copies outside Laravel's per-request
 * checks (an HTML cache, static exports, fragments, CDN copies, sitemaps)
 * registers one.
 *
 * Every step is idempotent, takes the affected site (null: every site) and
 * throws when it could not finish. WithdrawPublicReadProjectionsAction
 * records each one as owed before running it and clears it only once it has
 * succeeded, so a failed step is retried rather than lost.
 *
 * @experimental
 */
final class PublicReadWithdrawalSteps
{
    /** @var array<string, Closure(int|null): void> */
    private array $steps = [];

    /** @param Closure(int|null): void $step */
    public function register(string $key, Closure $step): void
    {
        throw_if($key === '' || mb_strlen($key) > 120, InvalidArgumentException::class, 'A public-read withdrawal step needs a key of 1 to 120 characters.');

        $this->steps[$key] = $step;
    }

    /** @return array<string, Closure(int|null): void> */
    public function all(): array
    {
        return $this->steps;
    }

    /** @return (Closure(int|null): void)|null */
    public function get(string $key): ?Closure
    {
        return $this->steps[$key] ?? null;
    }
}
