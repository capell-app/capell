<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolveFilamentIconAliasAction
{
    use AsFake;
    use AsObject;

    public function handle(string|BackedEnum|null $icon): ?string
    {
        if ($icon instanceof Heroicon) {
            return 'heroicon-' . $icon->value;
        }

        if ($icon instanceof BackedEnum) {
            $icon = $icon->value;
        }

        if (! is_string($icon)) {
            return null;
        }

        $icon = trim($icon);

        if ($icon === '') {
            return null;
        }

        return Heroicon::tryFrom($icon) instanceof Heroicon
            ? 'heroicon-' . $icon
            : $icon;
    }
}
