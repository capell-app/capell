# User Menu Registry

![Capell User Menu Registry screenshot](./images/screenshots/admin-dashboard.png)

Packages can add admin-only links to the Filament user menu through the Capell admin registry. Register items from a package service provider or admin provider after translations and routes are available.

Use this for personal attention and work queues: assigned workflow tasks, mentions, due reminders, drafts, or reviews waiting on the current user. Do not use it for global system health, diagnostics, or package status unless the current user is directly responsible for the item.

```php
use Capell\Admin\Facades\CapellAdmin;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;

CapellAdmin::registerUserMenuItem(
    key: 'capell-example.notes',
    label: fn (): string => __('capell-example::user-menu.notes'),
    icon: Heroicon::OutlinedBell,
    url: fn (Authenticatable $user): string => route('filament.admin.pages.example-notes'),
    badge: fn (Authenticatable $user): int => ExampleNote::query()
        ->whereBelongsTo($user, 'assignee')
        ->unread()
        ->count(),
    badgeColor: 'warning',
    visible: fn (Authenticatable $user): bool => $user->can('viewAny', ExampleNote::class),
    sort: 40,
);
```

The bridge registrar exposes the same API for package registration flows that receive a registrar instance:

```php
$registrar->userMenuItem(
    key: 'capell-example.notes',
    label: fn (): string => __('capell-example::user-menu.notes'),
    icon: Heroicon::OutlinedBell,
    url: fn (Authenticatable $user): string => route('filament.admin.pages.example-notes'),
    badge: fn (Authenticatable $user): int => ExampleNote::query()
        ->whereBelongsTo($user, 'assignee')
        ->unread()
        ->count(),
    badgeColor: 'warning',
    visible: fn (Authenticatable $user): bool => $user->can('viewAny', ExampleNote::class),
    sort: 40,
);
```

## API

`registerUserMenuItem()` accepts:

- `key`: Stable unique key. Prefix with the package name, for example `capell-example.notes`.
- `label`: Translated string or closure returning a translated string.
- `icon`: Optional Filament `Heroicon` or icon string.
- `url`: String or closure. Items with a blank URL are omitted.
- `badge`: Optional string, integer, or closure.
- `badgeColor`: Optional Filament color string or closure. Defaults to `primary` when a badge is present.
- `visible`: Boolean or closure. Return `false` when the current admin user should not see the item.
- `sort`: Lower numbers appear earlier.
- `group`: Optional metadata for packages that need to identify their own items later.

Closures receive the current authenticated admin user when they are evaluated. If there is no authenticated admin user, package menu items are not resolved.

## Badge Rules

Badges are intended for small attention counts:

- `null`, an empty string, `0`, or a negative numeric value hides the badge.
- Numeric values above `99` display as `99+`.
- `badgeColor` is only applied when a badge is visible.
- Exceptions thrown by a menu item callback are reported and that item is omitted, so one package cannot break the whole user menu.

## Translations

Keep all user-facing text in the package translation files:

```php
// packages/example/resources/lang/en/user-menu.php

return [
    'notes' => 'Notes',
];
```
