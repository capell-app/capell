<?php

declare(strict_types=1);

namespace Capell\Core\Support\Settings;

use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Contracts\SettingsSchema;
use InvalidArgumentException;

class SettingsSchemaRegistry
{
    /** @var array<string, SettingsGroupMetadata> */
    private array $metadata = [];

    /**
     * Map of group => array of schema class names
     *
     * @var array<string, array<string, class-string<SettingsSchema>>>
     */
    private array $schemas = [];

    /**
     * Map of group => primary settings class (for saving/hydration)
     *
     * @var array<string, class-string>
     */
    private array $settingsClasses = [];

    /**
     * Register a schema class for a group
     *
     * @param  string  $group  Settings group (e.g., 'core', 'admin', 'frontend')
     * @param  class-string<SettingsSchema>  $schemaClass  Must implement SettingsSchema
     * @param  ?string  $key  Unique identifier for this schema in the group (default: short class name)
     *
     * @throws InvalidArgumentException if schema class does not implement SettingsSchema
     */
    public function register(string $group, string $schemaClass, ?string $key = null): void
    {
        if (! class_exists($schemaClass) || ! is_a($schemaClass, SettingsSchema::class, true)) {
            throw new InvalidArgumentException(
                sprintf('Schema class %s must implement %s', $schemaClass, SettingsSchema::class),
            );
        }

        $key ??= class_basename($schemaClass);
        $this->schemas[$group] ??= [];
        $this->schemas[$group][$key] = $schemaClass;
    }

    /**
     * Register the primary settings class for a group
     * Used for form hydration and saving
     *
     * @param  class-string<SettingsContract>  $settingsClass  Must implement SettingsContract
     *
     * @throws InvalidArgumentException if settings class does not exist, does not implement the contract, or is registered under the wrong group
     */
    public function registerSettingsClass(string $group, string $settingsClass): void
    {
        if (! class_exists($settingsClass)) {
            throw new InvalidArgumentException(
                sprintf('Settings class %s does not exist', $settingsClass),
            );
        }

        if (! is_a($settingsClass, SettingsContract::class, true)) {
            throw new InvalidArgumentException(
                sprintf('Settings class %s must implement %s', $settingsClass, SettingsContract::class),
            );
        }

        if ($settingsClass::group() !== $group) {
            throw new InvalidArgumentException(
                sprintf(
                    'Settings class %s belongs to group %s, cannot register under %s',
                    $settingsClass,
                    $settingsClass::group(),
                    $group,
                ),
            );
        }

        $this->settingsClasses[$group] = $settingsClass;

        $this->registerSchemaFromSettingsClass($group, $settingsClass);
    }

    public function registerMetadata(SettingsGroupMetadata $metadata): void
    {
        $this->metadata[$metadata->group] = $metadata;
    }

    /**
     * Replace a schema class in a group
     * Useful for overriding default schemas
     *
     * @param  class-string<SettingsSchema>  $schemaClass
     * @param  string  $key  Schema identifier (must exist)
     *
     * @throws InvalidArgumentException if key does not exist
     */
    public function replace(string $group, string $schemaClass, string $key): void
    {
        if (! isset($this->schemas[$group][$key])) {
            throw new InvalidArgumentException(
                sprintf('Schema key %s not found in group %s', $key, $group),
            );
        }

        $this->register($group, $schemaClass, $key);
    }

    /**
     * Remove a schema from a group
     *
     * @param  string  $key  Schema identifier
     */
    public function remove(string $group, string $key): void
    {
        unset($this->schemas[$group][$key]);

        if (isset($this->schemas[$group]) && blank($this->schemas[$group])) {
            unset($this->schemas[$group]);
        }
    }

    /**
     * Remove all schemas from a group
     */
    public function removeGroup(string $group): void
    {
        unset($this->schemas[$group], $this->settingsClasses[$group], $this->metadata[$group]);
    }

    /**
     * Get all schema classes for a group
     *
     * @return array<string, class-string<SettingsSchema>>
     */
    public function getSchemas(string $group): array
    {
        return $this->schemas[$group] ?? [];
    }

    /**
     * Get a specific schema by group and key
     *
     * @return class-string<SettingsSchema>|null
     */
    public function getSchema(string $group, string $key): ?string
    {
        return $this->schemas[$group][$key] ?? null;
    }

    /**
     * Get the primary settings class for a group
     *
     * @return class-string|null
     */
    public function getSettingsClass(string $group): ?string
    {
        return $this->settingsClasses[$group] ?? null;
    }

    public function getMetadata(string $group): ?SettingsGroupMetadata
    {
        return $this->metadata[$group] ?? null;
    }

    public function isFirstPartyGroup(string $group): bool
    {
        return in_array($group, ['core', 'admin', 'frontend', 'theme_studio'], true);
    }

    /**
     * @return array<string>
     */
    public function getFirstPartyGroups(): array
    {
        return array_values(array_filter(
            $this->getGroups(),
            $this->isFirstPartyGroup(...),
        ));
    }

    /**
     * Get all registered groups
     *
     * @return array<string>
     */
    public function getGroups(): array
    {
        return array_keys($this->schemas);
    }

    /**
     * Check if a group has any schemas registered
     */
    public function hasGroup(string $group): bool
    {
        return isset($this->schemas[$group]) && filled($this->schemas[$group]);
    }

    /**
     * Get all registered schemas (all groups)
     *
     * @return array<string, array<string, class-string<SettingsSchema>>>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * @param  class-string<SettingsContract>  $settingsClass
     */
    private function registerSchemaFromSettingsClass(string $group, string $settingsClass): void
    {
        if (! method_exists($settingsClass, 'schema')) {
            return;
        }

        $schemaClass = $settingsClass::schema();

        if (! is_string($schemaClass) || ! is_a($schemaClass, SettingsSchema::class, true)) {
            return;
        }

        $this->register($group, $schemaClass);
    }
}
