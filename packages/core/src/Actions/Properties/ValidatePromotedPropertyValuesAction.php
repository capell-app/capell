<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\Page;
use Capell\Core\Observers\PageObserver;
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
 * Only fields that are changing are checked: a rule added later never blocks
 * an unrelated save of an older page.
 *
 * @method static void run(Page $page)
 */
final class ValidatePromotedPropertyValuesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly PropertyValueValidator $validator) {}

    /**
     * @throws ValidationException
     */
    public function handle(Page $page): void
    {
        if ($page->blueprint_id === null || $page->site_id === null) {
            return;
        }

        $promoted = ResolveEffectiveDefinitionsAction::run($page)->filter(
            static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null
                && $definition->constraints instanceof PropertyConstraintsData,
        );

        foreach ($promoted as $definition) {
            $field = (string) $definition->promotedField;

            if ($page->exists && ! $page->isDirty(explode('.', $field)[0])) {
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
                $definition->key,
            );
        }
    }
}
