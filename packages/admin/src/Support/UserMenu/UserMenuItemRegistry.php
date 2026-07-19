<?php

declare(strict_types=1);

namespace Capell\Admin\Support\UserMenu;

use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;

/** @extends AbstractKeyedRegistry<UserMenuItemData> */
class UserMenuItemRegistry extends AbstractKeyedRegistry
{
    private int $generation = 0;

    public function __construct(private readonly Application $application) {}

    public function register(UserMenuItemData $item): void
    {
        if ($item->key === '') {
            return;
        }

        $this->generation++;
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
        return $this->application->make(UserMenuItemResolver::class)->resolve(
            definitions: $this->allItems(),
            user: $user,
            generation: $this->generation,
        );
    }

    public function clear(): void
    {
        $this->clearItems();
        $this->generation++;
    }
}
