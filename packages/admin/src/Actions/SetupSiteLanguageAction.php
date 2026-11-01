<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Permissions\SiteAccess;
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
            $path = SiteDomainAddressing::languagePath($siteDomain->path, $siteDomain->language?->code, $language->code);

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

        // The owning site's URL and name are only disclosed to an editor who can
        // access that site; everyone else learns only that the address is taken.
        $message = SiteAccess::current()->can($existing->site)
            ? __('capell-admin::message.site_language_route_taken', [
                'language' => $language->name,
                'url' => $existing->full_url,
                'site' => $existing->site->name,
            ])
            : __('capell-admin::message.site_language_route_taken_generic', [
                'language' => $language->name,
            ]);

        throw ValidationException::withMessages(['path' => $message]);
    }
}
