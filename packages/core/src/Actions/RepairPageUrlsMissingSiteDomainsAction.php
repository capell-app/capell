<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Gives every site and language that has page URLs an active site domain.
 *
 * Each repaired domain claims a routing identity no other domain holds, so the repair
 * can run on any state, including its own output, without a uniqueness violation.
 *
 * @method static int run()
 */
class RepairPageUrlsMissingSiteDomainsAction
{
    use AsFake;
    use AsObject;

    public function handle(): int
    {
        $repaired = 0;

        FindPageUrlsMissingSiteDomainsAction::run()
            ->unique(fn (PageUrl $pageUrl): string => sprintf('%d:%d', $pageUrl->site_id, $pageUrl->language_id))
            ->each(function (PageUrl $pageUrl) use (&$repaired): void {
                if (DB::transaction(fn (): bool => $this->repair($pageUrl->site, $pageUrl->language))) {
                    $repaired++;
                }
            });

        return $repaired;
    }

    private function repair(Site $site, Language $language): bool
    {
        $existing = SiteDomain::query()
            ->withTrashed()
            ->where('site_id', $site->getKey())
            ->where('language_id', $language->getKey())
            ->orderByRaw('deleted_at is not null')
            ->orderByDesc('status')
            ->first();

        $mount = $this->mountDomain($site, $existing);
        $address = $existing instanceof SiteDomain ? $existing->getAttributes() : ($mount?->getAttributes() ?? []);
        $path = $this->freePath($address, $this->candidatePaths($existing, $mount, $language), $existing);

        if ($path === false) {
            // Every address this language could take already routes elsewhere; the doctor keeps reporting it.
            return false;
        }

        if ($existing instanceof SiteDomain) {
            $existing->forceFill(['path' => $path, 'status' => true]);

            if ($existing->trashed()) {
                $existing->restore();
            } else {
                $existing->save();
            }

            return true;
        }

        SiteDomain::query()->create([
            'site_id' => $site->getKey(),
            'language_id' => $language->getKey(),
            'scheme' => $address['scheme'] ?? null,
            'domain' => $address['domain'] ?? null,
            'port' => $address['port'] ?? null,
            'path' => $path,
            'default' => ! SiteDomain::query()->where('site_id', $site->getKey())->where('default', true)->exists(),
            'status' => true,
        ]);

        return true;
    }

    /**
     * The site's own route that a new language route is mounted inside.
     */
    private function mountDomain(Site $site, ?SiteDomain $existing): ?SiteDomain
    {
        return SiteDomain::query()
            ->where('site_id', $site->getKey())
            ->where('status', true)
            ->when($existing instanceof SiteDomain, fn ($query) => $query->whereKeyNot($existing?->getKey()))
            ->with('language')
            ->orderByDesc('default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<string|null>
     */
    private function candidatePaths(?SiteDomain $existing, ?SiteDomain $mount, Language $language): array
    {
        $paths = [];

        if ($existing instanceof SiteDomain) {
            $paths[] = $existing->path;
        }

        $paths[] = $mount instanceof SiteDomain
            ? SiteDomainAddressing::languagePath($mount->path, $mount->language?->code, $language->code)
            : null;
        $paths[] = SiteDomainAddressing::languagePath(null, null, $language->code);

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $address
     * @param  list<string|null>  $paths
     */
    private function freePath(array $address, array $paths, ?SiteDomain $existing): string|false|null
    {
        $scheme = SiteDomainAddressing::normalizeScheme($address['scheme'] ?? null);
        $host = SiteDomainAddressing::normalizeHost($address['domain'] ?? null);
        $port = SiteDomainAddressing::normalizeStoredPort($scheme, $address['port'] ?? null);

        foreach (array_unique($paths, SORT_REGULAR) as $path) {
            $identity = SiteDomainAddressing::routingIdentity($scheme, $host, $port, $path);

            $taken = SiteDomain::query()
                ->withTrashed()
                ->where('routing_identity', $identity)
                ->when($existing instanceof SiteDomain, fn ($query) => $query->whereKeyNot($existing?->getKey()))
                ->exists();

            if (! $taken) {
                return $path;
            }
        }

        return false;
    }
}
