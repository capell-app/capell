<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Contracts\SettingsSchema;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSettingsSchemaComponentsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  class-string<SettingsSchema>  $schemaClass
     * @return array<int, mixed>
     */
    public function handle(string $schemaClass, Schema $schema): array
    {
        if (! is_a($schemaClass, SettingsSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Settings schema %s must implement %s and define a callable static make method to render in the admin panel.',
                $schemaClass,
                SettingsSchema::class,
            ));
        }

        $factory = [$schemaClass, 'make'];

        if (! is_callable($factory)) {
            throw new InvalidArgumentException(sprintf(
                'Settings schema %s must implement %s and define a callable static make method to render in the admin panel.',
                $schemaClass,
                SettingsSchema::class,
            ));
        }

        $components = $factory($schema);

        if (! is_array($components)) {
            throw new InvalidArgumentException(sprintf(
                'Settings schema %s::make() must return an array.',
                $schemaClass,
            ));
        }

        return $components;
    }
}
