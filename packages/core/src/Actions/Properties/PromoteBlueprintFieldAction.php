<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertyDefinition;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * Maps (or unmaps) a blueprint field path onto a property, so existing
 * content feeds the agent layer without re-entry. The FIELD is the source of
 * truth for a promoted property: {@see SetPagePropertyValuesAction}
 * rejects any direct write to a promoted property, and
 * {@see SyncPromotedFieldValuesAction} is the only path that writes it, on
 * every page save.
 *
 * Unpromoting (passing `fieldPath: null`) stops the sync going forward but
 * deliberately leaves the property's last-synced value in place — it was
 * real content a moment ago, and dropping it silently would be a data loss
 * bug wearing a feature's clothes.
 */
final class PromoteBlueprintFieldAction
{
    use AsFake;
    use AsObject;

    public function handle(Blueprint $blueprint, PropertyDefinition $definition, ?string $fieldPath): void
    {
        $attachment = BlueprintPropertySet::query()
            ->where('blueprint_id', $blueprint->id)
            ->where('property_set_id', $definition->property_set_id)
            ->first();

        if (! $attachment instanceof BlueprintPropertySet) {
            throw new RuntimeException(sprintf(
                'Property set [%d] is not attached to blueprint [%d]; attach it before promoting a field to it.',
                $definition->property_set_id,
                $blueprint->id,
            ));
        }

        $overrides = $attachment->overrides ?? [];
        $propertyOverride = $overrides[$definition->key] ?? [];

        if ($fieldPath === null) {
            unset($propertyOverride['promoted_field']);
        } else {
            $propertyOverride['promoted_field'] = $fieldPath;
        }

        $overrides[$definition->key] = $propertyOverride;

        $attachment->update(['overrides' => $overrides]);
    }
}
