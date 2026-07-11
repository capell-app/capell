<?php

declare(strict_types=1);

namespace Capell\Admin\Macros\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * @mixin Action
 */
class ActionMacros
{
    /**
     * @return Closure(string $statePath, string|Closure $labelOn, null|string|Closure $labelOff): Action
     *
     * @return-closure-this Action
     */
    public function checkbox(): Closure
    {
        return fn (string $statePath, string|Closure $labelOn, null|string|Closure $labelOff = null): Action => $this->link()
            ->label(function (Action $action, Get $get) use ($statePath, $labelOn, $labelOff): string {
                $labelOff ??= $labelOn;

                return (bool) $get($statePath) ? $action->evaluate($labelOn) : $action->evaluate($labelOff);
            })
            ->icon(fn (Get $get): ?string => (bool) $get($statePath) ? null : 'heroicon-o-check')
            ->action(function (Get $get, Set $set) use ($statePath): void {
                $set($statePath, ! (bool) $get($statePath));
            });
    }

    /**
     * @return Closure(string $body): Action
     *
     * @return-closure-this Action
     */
    public function successNotificationBody(): Closure
    {
        return fn (string $body): Action => $this->successNotification(
            fn (Notification $notification): Notification => $notification->body($body),
        );
    }
}
