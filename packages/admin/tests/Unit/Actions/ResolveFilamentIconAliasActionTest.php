<?php

declare(strict_types=1);

use Capell\Admin\Actions\ResolveFilamentIconAliasAction;
use Filament\Support\Icons\Heroicon;

it('resolves Filament heroicon enums and legacy backing values to Blade aliases', function (): void {
    expect(ResolveFilamentIconAliasAction::run(Heroicon::OutlinedSparkles))
        ->toBe('heroicon-o-sparkles')
        ->and(ResolveFilamentIconAliasAction::run('o-squares-2x2'))
        ->toBe('heroicon-o-squares-2x2')
        ->and(ResolveFilamentIconAliasAction::run('heroicon-o-document-text'))
        ->toBe('heroicon-o-document-text')
        ->and(ResolveFilamentIconAliasAction::run('custom-package-icon'))
        ->toBe('custom-package-icon')
        ->and(ResolveFilamentIconAliasAction::run(''))
        ->toBeNull();
});
