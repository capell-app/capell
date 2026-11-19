<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Actions\Properties\ResolveEffectiveDefinitionsAction;
use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Reads a page's property values in the shape a SiteSpec page carries, the
 * inverse of {@see ApplySiteSpecPagePropertiesAction}. A SiteSpec holds one value
 * per property in one language, so a property that points at a term, page or media
 * item (an ID that means nothing on another copy of the site) or holds several
 * values, or is filled from a page field (a promoted property: it cannot be written
 * directly, so applying it would fail the whole import), is left out and named, never
 * written as something applying would change. A public export also leaves out
 * properties that are not visible to agents, as the public property bag does.
 *
 * @method static array{properties: array<string, mixed>, omitted: list<string>} run(Page $page, int $languageId, bool $public = false)
 */
final class ExportSiteSpecPagePropertiesAction
{
    use AsFake;
    use AsObject;

    /** @return array{properties: array<string, mixed>, omitted: list<string>} */
    public function handle(Page $page, int $languageId, bool $public = false): array
    {
        $translationId = $page->translations()->where('language_id', $languageId)->value('id');
        $properties = [];
        $omitted = [];

        foreach (ResolveEffectiveDefinitionsAction::run($page)->filter(static fn (EffectivePropertyDefinitionData $definition): bool => ! $public || $definition->agentVisible)->sortBy(static fn (EffectivePropertyDefinitionData $definition): string => $definition->qualifiedKey()) as $definition) {
            $rows = PagePropertyValue::query()
                ->where('site_id', $page->site_id)
                ->where('page_id', $page->getKey())
                ->where('property_definition_id', $definition->definitionId)
                ->where('translation_id', $definition->localised && is_int($translationId) ? $translationId : null)
                ->orderBy('position')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            if ($definition->promotedField !== null || $definition->multiple || $rows->count() > 1 || in_array($definition->type, [PropertyType::TermReference, PropertyType::EntryReference, PropertyType::Media], true)) {
                $omitted[] = $definition->qualifiedKey();

                continue;
            }

            $value = PropertyValueData::fromPageValue($rows->firstOrFail(), $definition->key, $definition->type);
            $exported = $value->value instanceof DateTimeInterface ? $value->value->format(DateTimeInterface::ATOM) : $value->value;

            if ($exported === null) {
                continue;
            }

            $properties[$definition->qualifiedKey()] = $value->currency !== null || $value->unit !== null
                ? array_filter(['value' => $exported, 'currency' => $value->currency, 'unit' => $value->unit], static fn (mixed $part): bool => $part !== null)
                : $exported;
        }

        return ['properties' => $properties, 'omitted' => $omitted];
    }
}
