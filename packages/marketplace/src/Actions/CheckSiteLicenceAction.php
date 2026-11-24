<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Data\SiteLicenceStatusData;
use Capell\Marketplace\Enums\SiteLicenceState;
use Capell\Marketplace\Services\MarketplaceClient;
use Capell\Marketplace\Support\Licensing\NonProductionDomain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/**
 * Checks this site's licence, as the licence terms describe: once a day, sending the site's domain and each licence
 * key and nothing else. It never changes how the site runs; the answer only decides whether admins see a notice. A
 * site with no paid packages, or on a local host, sends nothing; capell.app answers staging copies as free. Every paid package, Capell Pro and
 * third-party alike, is checked through this one call.
 */
final class CheckSiteLicenceAction
{
    use AsFake;
    use AsObject;

    public const string CACHE_KEY = 'capell-marketplace.site-licence';

    public function __construct(private readonly MarketplaceClient $client) {}

    public function handle(): ?SiteLicenceStatusData
    {
        $paid = $this->paidPackages();

        if ($paid === []) {
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        $domain = strtolower(rtrim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.'));

        if ($domain === '' || NonProductionDomain::is($domain)) {
            return $this->store(SiteLicenceState::NonProduction, [], null, null);
        }

        $keys = $this->licenceKeys();

        if ($keys === []) {
            return $this->store(SiteLicenceState::MissingKey, $paid, null, null);
        }

        $covered = [];
        $statuses = [];
        $sitesUsed = null;
        $siteLimit = null;

        foreach ($keys as $key) {
            try {
                $answer = $this->client->activateSiteLicence($domain, $key);
            } catch (Throwable $throwable) {
                // An unreachable marketplace never produces a notice: keep the last answer.
                Log::warning('Capell licence check could not reach the marketplace.', ['message' => $throwable->getMessage()]);

                return $this->current();
            }

            $status = is_string($answer['status'] ?? null) ? $answer['status'] : 'unlicensed';
            $statuses[] = $status;
            if (in_array($status, ['active', 'over_limit'], true)) {
                $sitesUsed = is_int($answer['sitesUsed'] ?? null) ? $answer['sitesUsed'] : $sitesUsed;
                $siteLimit = is_int($answer['siteLimit'] ?? null) ? $answer['siteLimit'] : $siteLimit;
            }

            if ($status === 'active') {
                $covered = [...$covered, ...array_filter((array) ($answer['packages'] ?? []), is_string(...))];
            }
        }

        // capell.app answers a staging copy of a site as free and records nothing about it.
        if (in_array('non_production', $statuses, true)) {
            return $this->store(SiteLicenceState::NonProduction, [], null, null);
        }

        $unlicensed = array_values(array_diff($paid, $covered));

        $state = match (true) {
            $unlicensed === [] => SiteLicenceState::Licensed,
            in_array('over_limit', $statuses, true) => SiteLicenceState::OverLimit,
            in_array('expired', $statuses, true) => SiteLicenceState::Expired,
            default => SiteLicenceState::Unlicensed,
        };

        return $this->store($state, $unlicensed, $sitesUsed, $siteLimit);
    }

    /** The last stored answer, read without any network call. */
    public function current(): ?SiteLicenceStatusData
    {
        $stored = Cache::get(self::CACHE_KEY);

        if (! is_array($stored) || ! is_string($stored['state'] ?? null) || SiteLicenceState::tryFrom($stored['state']) === null) {
            return null;
        }

        return new SiteLicenceStatusData(
            state: SiteLicenceState::from($stored['state']),
            unlicensedPackages: array_values(array_filter((array) ($stored['unlicensed_packages'] ?? []), is_string(...))),
            sitesUsed: is_int($stored['sites_used'] ?? null) ? $stored['sites_used'] : null,
            siteLimit: is_int($stored['site_limit'] ?? null) ? $stored['site_limit'] : null,
            checkedAt: CarbonImmutable::parse(is_string($stored['checked_at'] ?? null) ? $stored['checked_at'] : 'now'),
        );
    }

    /** @param list<string> $unlicensed */
    private function store(SiteLicenceState $state, array $unlicensed, ?int $sitesUsed, ?int $siteLimit): SiteLicenceStatusData
    {
        $checkedAt = CarbonImmutable::now();
        Cache::forever(self::CACHE_KEY, [
            'state' => $state->value,
            'unlicensed_packages' => $unlicensed,
            'sites_used' => $sitesUsed,
            'site_limit' => $siteLimit,
            'checked_at' => $checkedAt->toIso8601String(),
        ]);

        return new SiteLicenceStatusData($state, $unlicensed, $sitesUsed, $siteLimit, $checkedAt);
    }

    /** @return list<string> */
    private function paidPackages(): array
    {
        $registered = CapellCore::getInstalledPackages()
            ->filter(fn (PackageData $package): bool => $package->isPaid === true)
            ->keys()
            ->all();

        try {
            $flagged = CapellExtension::query()->where('is_paid_marketplace_extension', true)->pluck('composer_name')->all();
        } catch (Throwable) {
            $flagged = [];
        }

        $paid = array_values(array_unique(array_filter([...$registered, ...$flagged], is_string(...))));
        sort($paid);

        return $paid;
    }

    /** @return list<string> */
    private function licenceKeys(): array
    {
        $keys = config('capell-marketplace.licence.keys', []);

        return array_values(array_unique(array_filter(
            array_map(trim(...), is_array($keys) ? array_filter($keys, is_string(...)) : explode(',', (string) $keys)),
            static fn (string $key): bool => $key !== '',
        )));
    }
}
