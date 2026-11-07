<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Filament;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

/**
 * Walks a schema holding recovered state, which can hold another shape than the
 * current fields expect. Repeaters and builders are returned but not entered:
 * building their item schemas requires each item to already be an array.
 */
final class RecoverableSchemaComponents
{
    /**
     * @return iterable<Component>
     */
    public static function in(Schema $schema): iterable
    {
        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            if (! $component instanceof Component) {
                continue;
            }

            yield $component;
            if ($component instanceof Builder) {
                continue;
            }

            if ($component instanceof Repeater) {
                continue;
            }

            foreach ($component->getChildSchemas(withHidden: true) as $childSchema) {
                yield from self::in($childSchema);
            }
        }
    }
}
