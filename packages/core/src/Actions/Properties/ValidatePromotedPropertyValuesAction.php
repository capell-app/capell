<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Observers\PageObserver;
use Capell\Core\Support\Properties\PromotedFieldChanges;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Checks a page's changed, promoted fields against their properties' rules
 * BEFORE the page row is written ({@see PageObserver}). The promoted value is
 * only synced after the save, and a page save is not transactional, so a rule
 * failure found then would leave the field saved and its property stale.
 *
 * Changing fields are always enforced ({@see PromotedFieldChanges}), as is
 * every promoted field on a move to another blueprint or site. A field this
 * save leaves as it was is enforced where developers work and only reported
 * in production, so a rule added later never blocks an unrelated production
 * save of an older page.
 *
 * Runs on every page create and update, so it first reads the blueprint's
 * promotions (one small query, plus one more only when there are any) and
 * returns before resolving definitions when no promoted field has a rule.
 *
 * @method static void run(Page $page)
 */
final class ValidatePromotedPropertyValuesAction
{
    use AsFake;
    use AsObject;

    /** Errors are keyed `properties.<definition key>`, so a caller such as a SiteSpec import can tell them apart and re-key them. */
    public const string ERROR_KEY_PREFIX = 'properties.';

    public function __construct(private readonly PropertyValueValidator $validator) {}

    /**
     * @throws ValidationException
     */
    public function handle(Page $page): void
    {
        if ($page->blueprint_id === null || $page->site_id === null) {
            return;
        }

        if (! $this->hasConstrainedPromotedFields($page)) {
            return;
        }

        $promoted = ResolveEffectiveDefinitionsAction::run($page)->filter(
            static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null
                && $definition->constraints instanceof PropertyConstraintsData,
        );

        foreach ($promoted as $definition) {
            $field = (string) $definition->promotedField;

            $rawValue = data_get($page, $field);

            if ($rawValue === null) {
                continue;
            }

            $arguments = [
                $definition,
                SyncPromotedFieldValuesAction::coerce($definition, $rawValue),
                (int) $page->site_id,
                $page->exists ? (int) $page->getKey() : null,
                self::ERROR_KEY_PREFIX . $definition->key,
            ];

            if (PromotedFieldChanges::changingBeforeSave($page, $definition)) {
                $this->validator->assertConstraints(...$arguments);
            } else {
                $this->validator->assertOrReportUntouchedConstraints(...$arguments);
            }
        }
    }

    /**
     * Whether any field on the page's blueprint is promoted to a property that
     * carries rules, read without resolving the full definition list.
     */
    private function hasConstrainedPromotedFields(Page $page): bool
    {
        $promotedKeysBySet = [];

        foreach (BlueprintPropertySet::query()->where('blueprint_id', $page->blueprint_id)->get(['property_set_id', 'overrides']) as $attachment) {
            foreach ($attachment->overrides ?? [] as $key => $override) {
                $field = $override['promoted_field'] ?? null;

                if (is_string($field) && $field !== '') {
                    $promotedKeysBySet[(int) $attachment->property_set_id][] = (string) $key;
                }
            }
        }

        if ($promotedKeysBySet === []) {
            return false;
        }

        return PropertyDefinition::query()
            ->whereNotNull('constraints')
            ->where(static function (Builder $query) use ($promotedKeysBySet): void {
                foreach ($promotedKeysBySet as $propertySetId => $keys) {
                    $query->orWhere(static fn (Builder $set): Builder => $set
                        ->where('property_set_id', $propertySetId)
                        ->whereIn('key', $keys));
                }
            })
            ->exists();
    }
}
