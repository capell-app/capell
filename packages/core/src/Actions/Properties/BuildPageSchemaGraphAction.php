<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\SchemaGraphData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Projects public semantic values; qualified definition keys stay out of JSON-LD. */
final class BuildPageSchemaGraphAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, ?Language $language = null): ?SchemaGraphData
    {
        if (! $page->blueprint()->enabled()->accessible()->exists()) {
            return null;
        }

        $bag = ResolveAgentPropertyValuesAction::run($page, $language);
        $properties = [];
        $types = [];

        foreach ($bag->entries as $entry) {
            if ($entry->semantic === null) {
                continue;
            }

            if (preg_match('/\Aschema:([A-Za-z][A-Za-z0-9]*)\z/', (string) $entry->semantic, $matches) !== 1) {
                continue;
            }

            $value = ProjectAgentSchemaValueAction::run($entry, $page->site_id, $language);
            if ($value === null) {
                continue;
            }

            $properties[$matches[1]][] = $value;
            foreach (['commerce.product.' => 'Product', 'events.event.' => 'Event', 'content.article.' => 'Article'] as $prefix => $type) {
                if (str_starts_with((string) $entry->qualifiedKey, $prefix)) {
                    $types[$type] = $type;
                }
            }
        }

        if ($properties === []) {
            return null;
        }

        $node = [
            '@type' => count($types) > 1 ? array_values($types) : (array_values($types)[0] ?? 'WebPage'),
            '@context' => ['capellAgentSchema' => 'https://capell.app/agent-schema/version'],
            'capellAgentSchema' => 1,
        ];
        $languageId = $language?->id;
        $translation = $languageId !== null
            ? $page->translations()->where('language_id', $languageId)->first()
            : $page->translation;
        if ($translation?->title !== null) {
            $node['name'] = $translation->title;
        }

        $url = $page->pageUrls()->where('site_id', $page->site_id)->where('status', true)
            ->where(static fn (Builder $query): Builder => $query->whereNull('type')->orWhere('type', '!=', UrlTypeEnum::Redirect))
            ->whereHas('translation', static fn (Builder $query): Builder => $query
                ->when($languageId !== null, static fn (Builder $translation): Builder => $translation->where('language_id', $languageId)))
            ->whereHas('siteDomain', static fn (Builder $domain): Builder => $domain->where('status', true))
            ->when($languageId !== null, fn (Builder $query): Builder => $query->where('language_id', $languageId))
            ->orderBy('id')->first();
        if ($url !== null) {
            try {
                $fullUrl = $url->fullUrl();
            } catch (UrlMissingSiteDomainException) {
                $fullUrl = null;
            }

            if ($fullUrl !== null) {
                $node['@id'] = $fullUrl;
                $node['url'] = $fullUrl;
            }
        }

        foreach ($properties as $key => $values) {
            // Identity and contract metadata cannot be overwritten by author mappings.
            if (in_array($key, ['capellAgentSchema', 'url'], true)) {
                continue;
            }

            $node[$key] = count($values) === 1 ? $values[0] : $values;
        }

        if (isset($types['Product']) && (isset($node['price']) || isset($node['availability']))) {
            $offer = ['@type' => 'Offer'];
            if (isset($node['price'])) {
                $offer['priceSpecification'] = $node['price'];
                unset($node['price']);
            }

            if (isset($node['availability'])) {
                $offer['availability'] = $node['availability'];
                unset($node['availability']);
            }

            $node['offers'] = $offer;
        }

        return new SchemaGraphData([$node]);
    }
}
