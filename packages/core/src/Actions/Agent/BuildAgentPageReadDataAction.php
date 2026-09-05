<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Agent;

use Capell\Core\Actions\Properties\ProjectAgentSchemaValueAction;
use Capell\Core\Actions\Properties\ResolveAgentPropertyValuesAction;
use Capell\Core\Data\Agent\AgentPageReadData;
use Capell\Core\Data\Properties\AgentPropertyBagData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Public API projection, including the documented Capell property namespace. */
final class BuildAgentPageReadDataAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, Language $language): ?AgentPageReadData
    {
        if (! $page->blueprint()->enabled()->accessible()->exists()) {
            return null;
        }

        $bag = ResolveAgentPropertyValuesAction::run($page, $language);
        if ($bag->isEmpty()) {
            return null;
        }

        $url = $page->pageUrls()->where('site_id', $page->site_id)->where('language_id', $language->id)->enabled()
            ->where(static fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->whereHas('siteDomain', static fn (Builder $domain): Builder => $domain->where('status', true))
            ->whereHas('translation', static fn (Builder $translation): Builder => $translation->where('language_id', $language->id))
            ->with(['siteDomain', 'translation'])->orderBy('id')->first();
        if ($url === null) {
            return null;
        }

        try {
            $publicUrl = $url->fullUrl();
        } catch (UrlMissingSiteDomainException) {
            return null;
        }

        $properties = [];
        foreach ($bag->entries as $entry) {
            // Reuse the bag's stable public key convention, but project references
            // through the site/publication safety gate and retain repeated values.
            $key = array_key_first(new AgentPropertyBagData([$entry])->toSchemaOrgProperties());
            $value = ProjectAgentSchemaValueAction::run($entry, $page->site_id, $language);
            if ($key !== null && $value !== null) {
                $properties[$key][] = $value;
            }
        }

        foreach ($properties as $key => $values) {
            $properties[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return new AgentPageReadData($publicUrl, (string) $url->translation?->title, $properties);
    }
}
