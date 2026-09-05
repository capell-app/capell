<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\EffectivePropertyDefinitionData;
use Capell\Core\Data\Properties\PropertyValueData;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * The only path that writes a promoted property's value: reads the mapped
 * blueprint field from the page, coerces it, and writes it through
 * {@see SetPagePropertyValuesAction} with `$viaPromotedFieldSync: true`
 * (which is what makes that action accept the write — every other caller is
 * rejected for a promoted property). Invoked on every page save.
 *
 * A field currently holding `null` is left alone rather than clearing the
 * property — an in-progress edit that has not reached the promoted field yet
 * should not blank out a previously-synced value.
 */
final class SyncPromotedFieldValuesAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): void
    {
        $promoted = ResolveEffectiveDefinitionsAction::run($page)
            ->filter(static fn (EffectivePropertyDefinitionData $definition): bool => $definition->promotedField !== null);

        if ($promoted->isEmpty()) {
            return;
        }

        $values = [];

        foreach ($promoted as $definition) {
            $rawValue = data_get($page, (string) $definition->promotedField);

            if ($rawValue === null) {
                continue;
            }

            $values[] = new PropertyValueData(
                propertyKey: $definition->key,
                type: $definition->type,
                value: $this->coerce($definition, $rawValue),
            );
        }

        if ($values !== []) {
            SetPagePropertyValuesAction::run($page, $values, viaPromotedFieldSync: true);
        }
    }

    private function coerce(EffectivePropertyDefinitionData $definition, mixed $rawValue): mixed
    {
        return match (true) {
            $definition->type->isBoolean() => (bool) $rawValue,
            $definition->type->isNumeric() => is_numeric($rawValue) ? (float) $rawValue : null,
            default => is_scalar($rawValue) ? (string) $rawValue : null,
        };
    }
}
