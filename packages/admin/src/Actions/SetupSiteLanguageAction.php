<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static void run(Site $site, Language $language)
 */
class SetupSiteLanguageAction
{
    use AsFake;
    use AsObject;

    private function setUp(Site $site, Language $language): void
    {
        $translation = $site->translations()->first();
        $translationValues = $translation !== null
            ? Arr::except($translation->replicate()->attributesToArray(), [
                'id',
                'language_id',
                'translatable_type',
                'translatable_id',
                'created_by',
                'updated_by',
                'deleted_by',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            : [];

        $site->translations()->createOrFirst(['language_id' => $language->id], $translationValues);

        if ($site->siteDomains()->where('language_id', $language->id)->exists()) {
            return;
        }

        $siteDomain = $site->siteDomains()->first();

        if ($siteDomain !== null) {
            $path = $this->languagePath($siteDomain, $language);

            $replica = $siteDomain->replicate([
                'routing_identity',
            ])->fill([
                'default' => false,
                'language_id' => $language->id,
                'path' => $path,
            ]);

            $this->assertRouteIsFree($replica, $language);

            $replica->save();

            return;
        }

        $site->siteDomains()->create(['language_id' => $language->id]);
    }

    public function handle(Site $site, Language $language): void
    {
        DB::transaction(function () use ($site, $language): void {
            $this->setUp($site, $language);
        });
    }

    private function assertRouteIsFree(SiteDomain $candidate, Language $language): void
    {
        $identity = SiteDomainAddressing::routingIdentity(
            $candidate->getAttributes()['scheme'] ?? null,
            $candidate->getAttributes()['domain'] ?? null,
            $candidate->getAttributes()['port'] ?? null,
            $candidate->path,
        );

        $existing = SiteDomain::query()->where('routing_identity', $identity)->with('site')->first();

        if (! $existing instanceof SiteDomain) {
            return;
        }

        throw ValidationException::withMessages([
            'path' => __('capell-admin::message.site_language_route_taken', [
                'language' => $language->name,
                'url' => $existing->full_url,
                'site' => $existing->site->name,
            ]),
        ]);
    }

    /**
     * Keep the language route inside the path the site is already mounted on.
     */
    private function languagePath(SiteDomain $siteDomain, Language $language): string
    {
        $segments = array_values(array_filter(
            explode('/', SiteDomainAddressing::normalizePath($siteDomain->path)),
            static fn (string $segment): bool => $segment !== '',
        ));

        // A first domain that is itself a language route (/showcase/en) is not part of the mount.
        if ($segments !== [] && $siteDomain->language?->code === end($segments)) {
            array_pop($segments);
        }

        $segments[] = $language->code;

        return '/' . implode('/', $segments);
    }
}
