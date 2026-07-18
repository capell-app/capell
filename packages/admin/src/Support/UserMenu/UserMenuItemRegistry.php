<?php

declare(strict_types=1);

namespace Capell\Admin\Support\UserMenu;

use Capell\Admin\Actions\UserMenu\ResolveUserMenuItemsAction;
use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;

/** @extends AbstractKeyedRegistry<UserMenuItemData> */
class UserMenuItemRegistry extends AbstractKeyedRegistry
{
    /** @var array<string, array<string, Action>> */
    private array $resolvedItems = [];

    public function register(UserMenuItemData $item): void
    {
        if ($item->key === '') {
            return;
        }

        $this->resolvedItems = [];
        $this->setItem($item->key, $item);
    }

    /** @return array<string, UserMenuItemData> */
    public function definitions(): array
    {
        return $this->allItems();
    }

    /** @return array<string, Action> */
    public function resolved(?Authenticatable $user): array
    {
        $cacheKey = $user instanceof Authenticatable ? (string) $user->getAuthIdentifier() : 'guest';

        return $this->resolvedItems[$cacheKey] ??= ResolveUserMenuItemsAction::run(
            definitions: $this->allItems(),
            user: $user,
        );
    }

    public function clear(): void
    {
        $this->clearItems();
        $this->resolvedItems = [];
    }
}
