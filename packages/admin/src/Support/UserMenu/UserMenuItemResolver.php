<?php

declare(strict_types=1);

namespace Capell\Admin\Support\UserMenu;

use Capell\Admin\Actions\UserMenu\ResolveUserMenuItemsAction;
use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;

final class UserMenuItemResolver
{
    /** @var array<string, array<string, Action>> */
    private array $resolvedItems = [];

    /**
     * @param  array<string, UserMenuItemData>  $definitions
     * @return array<string, Action>
     */
    public function resolve(array $definitions, ?Authenticatable $user, int $generation): array
    {
        $cacheKey = $generation . ':' . ($user instanceof Authenticatable
            ? $user::class . ':' . $user->getAuthIdentifier()
            : 'guest');

        return $this->resolvedItems[$cacheKey] ??= ResolveUserMenuItemsAction::run(
            definitions: $definitions,
            user: $user,
        );
    }
}
