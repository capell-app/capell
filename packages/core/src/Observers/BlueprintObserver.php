<?php

declare(strict_types=1);

namespace Capell\Core\Observers;

use Capell\Core\Actions\CaptureBlueprintSchemaSnapshotAction;
use Capell\Core\Actions\GenerateUniqueKeyAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Arr;

class BlueprintObserver
{
    public function creating(Blueprint $blueprint): void
    {
        $key = $blueprint->getAttribute('key');

        if (! is_string($key) || $key === '') {
            $blueprint->key = GenerateUniqueKeyAction::run($blueprint);
        }

        if ($blueprint->key === 'default') {
            $blueprintType = $blueprint->getAttribute('type');
            $blueprintValue = is_object($blueprintType) && property_exists($blueprintType, 'name')
                ? $blueprintType->name
                : $blueprintType;

            $blueprint->default = Blueprint::query()->where('type', $blueprintValue)->default()->doesntExist();
        }
    }

    public function updating(Blueprint $blueprint): void
    {
        if (! $this->hasSchemaDefinitionChanges($blueprint)) {
            return;
        }

        CaptureBlueprintSchemaSnapshotAction::run($blueprint, 'blueprint_schema_update', [
            'changed' => array_values(array_intersect(
                ['admin', 'meta', 'type'],
                array_keys($blueprint->getDirty()),
            )),
        ]);
    }

    public function saved(Blueprint $blueprint): void
    {
        $admin = $blueprint->admin ?? [];

        if (array_key_exists('role_restrictions', $admin) && is_array($admin['role_restrictions'])) {
            $blueprint->syncRoleRestrictions(
                array_values(array_map(intval(...), $admin['role_restrictions'])),
            );

            unset($admin['role_restrictions']);

            $blueprint->forceFill(['admin' => $admin])->saveQuietly();
        }

        CapellCoreHelper::flushCache([
            CacheEnum::Type,
            CacheEnum::MissingDefaultTypes,
            CacheEnum::HasSiteType,
            CacheEnum::FirstPageByTypeForSite,
            CacheEnum::ModelDefaultExists,
            CacheEnum::RelationExists,
        ]);
    }

    public function deleted(Blueprint $blueprint): void
    {
        $this->saved($blueprint);
    }

    public function restored(Blueprint $blueprint): void
    {
        $this->saved($blueprint);
    }

    private function hasSchemaDefinitionChanges(Blueprint $blueprint): bool
    {
        if ($blueprint->isDirty('type')) {
            return true;
        }

        if ($this->schemaMetadata($blueprint->getOriginal('admin')) !== $this->schemaMetadata($blueprint->admin)) {
            return true;
        }

        return $this->schemaMetadata($blueprint->getOriginal('meta')) !== $this->schemaMetadata($blueprint->meta);
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaMetadata(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        return Arr::except($metadata, ['role_restrictions']);
    }
}
