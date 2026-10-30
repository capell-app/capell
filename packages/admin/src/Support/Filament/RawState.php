<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Filament;

use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Arrayable;

final class RawState
{
    /**
     * @return array<string, mixed>
     */
    public static function array(mixed $state): array
    {
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        return is_array($state) ? $state : [];
    }

    /**
     * Read a field's state the way `$get()` resolves it, but without the field's state casts.
     * A relationship Select's casts need a model instance, which embedded and unsaved forms
     * do not have.
     */
    public static function of(Component $component, string $path): mixed
    {
        $statePath = $component->resolveRelativeStatePath($path);
        $field = $component->getRootContainer()->getComponentByStatePath(
            $statePath,
            withHidden: true,
            withAbsoluteStatePath: true,
            skipComponentsChildContainersWhileSearching: [$component],
        );

        return $field instanceof Component ? $field->getRawState() : data_get($component->getLivewire(), $statePath);
    }
}
