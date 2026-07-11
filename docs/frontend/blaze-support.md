# Blaze Support

![Capell Blaze Support screenshot](../images/generated/admin/site-health-page.png)

Capell registers anonymous Blade component directories with Livewire Blaze using function compilation only.

## Default Strategy

- `compile: true`
- `memo: false`
- `fold: false`

This is the safe baseline for admin and frontend package components.

## Advanced Strategy Rules

Memoization may be enabled only for components with no slots.

Folding may be enabled only after checking the component does not read global state, request/session/auth data, validation errors, shared view data, render hooks, Blade stacks, or CSRF tokens.

## Current Advanced Strategy Exclusions

- `packages/frontend/resources/views/components/app/head/index.blade.php` uses `@yield`.
- `packages/admin/resources/views/components/schemas/collapsible-tabs.blade.php` calls Filament render hooks.
- `packages/admin/resources/views/components/tables/selection-indicator.blade.php` calls Filament render hooks.

## Rollout

In a consuming Laravel app, run `php artisan view:clear` after changing Blaze registrations. In this monorepo, run `composer clear:views`.
Set `BLAZE_ENABLED=false` to compare against Blade rendering.
Set `BLAZE_DEBUG=true` to use Blaze's debug overlay and profiler.
Set `CAPELL_BLAZE_THROW=true` in local development when auditing fold candidates.

## Next

- [Frontend](index.md)
- [Frontend guide](guide.md)
