<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Admin\Filament\Contracts\HasSchema;
use Capell\Core\Contracts\SettingsSchema;
use Filament\Schemas\Schema;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSettingsSchemaComponentsAction
{
    use AsObject;

    /**
     * @param  class-string<SettingsSchema>  $schemaClass
     * @return array<int, mixed>
     */
    public function handle(string $schemaClass, Schema $schema): array
    {
        if (! is_a($schemaClass, HasSchema::class, true)) {
            throw new InvalidArgumentException(sprintf(
                'Settings schema %s must implement %s to render in the admin panel.',
                $schemaClass,
                HasSchema::class,
            ));
        }

        return $schemaClass::make($schema);
    }
}
