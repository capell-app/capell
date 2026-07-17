<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\UserMenu;

use Capell\Admin\Data\UserMenu\UserMenuItemData;
use Closure;
use Filament\Actions\Action;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class ResolveUserMenuItemsAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, UserMenuItemData>  $definitions
     * @return array<string, Action>
     */
    public function handle(array $definitions, ?Authenticatable $user): array
    {
        if (! $user instanceof Authenticatable) {
            return [];
        }

        return collect($definitions)
            ->sortBy([
                fn (UserMenuItemData $item): int => $item->sort,
                fn (UserMenuItemData $item): string => $item->key,
            ])
            ->mapWithKeys(function (UserMenuItemData $item) use ($user): array {
                try {
                    if (! $this->resolveBool($item->visible, $user)) {
                        return [];
                    }

                    $url = $this->resolveNullableString($item->url, $user);

                    if ($url === null || trim($url) === '') {
                        return [];
                    }

                    $menuItem = Action::make($item->key)
                        ->label($this->resolveString($item->label, $user))
                        ->sort($item->sort)
                        ->url($url);

                    if ($item->icon !== null) {
                        $menuItem->icon($item->icon);
                    }

                    $badge = $this->resolveBadge($item->badge, $user);

                    if ($badge !== null) {
                        $menuItem->badge($badge);
                        $menuItem->badgeColor($this->resolveNullableString($item->badgeColor, $user) ?? 'primary');
                    }

                    return [$item->key => $menuItem];
                } catch (Throwable $throwable) {
                    report($throwable);

                    return [];
                }
            })
            ->all();
    }

    private function resolveBool(bool|Closure $value, Authenticatable $user): bool
    {
        return (bool) $this->evaluate($value, $user);
    }

    private function resolveString(string|Closure $value, Authenticatable $user): string
    {
        return (string) $this->evaluate($value, $user);
    }

    private function resolveNullableString(string|Closure|null $value, Authenticatable $user): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $this->evaluate($value, $user);
    }

    private function resolveBadge(int|string|Closure|null $value, Authenticatable $user): ?string
    {
        if ($value === null) {
            return null;
        }

        $resolved = $this->evaluate($value, $user);

        if ($resolved === null || $resolved === '') {
            return null;
        }

        if (is_numeric($resolved) && (int) $resolved <= 0) {
            return null;
        }

        if (is_numeric($resolved) && (int) $resolved > 99) {
            return '99+';
        }

        return (string) $resolved;
    }

    private function evaluate(mixed $value, Authenticatable $user): mixed
    {
        if ($value instanceof Closure) {
            return $value($user);
        }

        return $value;
    }
}
