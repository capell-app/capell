<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\Page;
use Capell\Core\Observers\PageObserver;
use Capell\Core\Support\Properties\PromotedFieldChanges;
use Capell\Core\Support\Properties\PropertyValueValidator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Checks a page's changed, promoted fields against their properties' rules
 * BEFORE the page row is written ({@see PageObserver}). The promoted value is
 * only synced after the save, and a page save is not transactional, so a rule
 * failure found then would leave the field saved and its property stale.
 *
 * Only fields that are changing are checked ({@see PromotedFieldChanges}): a
 * rule added later never blocks an unrelated save of an older page, while a
 * move to another blueprint or site checks every promoted field against the
 * rules that will then apply.
 *
 * Runs on every page create and update, so it first reads only the
 * blueprint's promotion paths (one small query) and returns before resolving
 * definitions when no promoted field is changing.
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

        if (! $this->anyPromotedFieldChanging($page)) {
            return;
        }

        $promoted = ResolveEffectiveDefinitionsAction::run($page)->filter(
            static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null
                && $definition->constraints instanceof PropertyConstraintsData,
        );

        foreach ($promoted as $definition) {
            $field = (string) $definition->promotedField;

            if (! PromotedFieldChanges::changingBeforeSave($page, $field)) {
                continue;
            }

            $rawValue = data_get($page, $field);

            if ($rawValue === null) {
                continue;
            }

            $this->validator->assertConstraints(
                $definition,
                SyncPromotedFieldValuesAction::coerce($definition, $rawValue),
                (int) $page->site_id,
                $page->exists ? (int) $page->getKey() : null,
                self::ERROR_KEY_PREFIX . $definition->key,
            );
        }
    }

    private function anyPromotedFieldChanging(Page $page): bool
    {
        $overrides = BlueprintPropertySet::query()
            ->where('blueprint_id', $page->blueprint_id)
            ->pluck('overrides');

        foreach ($overrides as $override) {
            foreach (is_array($override) ? $override : [] as $definitionOverride) {
                $field = is_array($definitionOverride) ? ($definitionOverride['promoted_field'] ?? null) : null;

                if (is_string($field) && $field !== '' && PromotedFieldChanges::changingBeforeSave($page, $field)) {
                    return true;
                }
            }
        }

        return false;
    }
}
