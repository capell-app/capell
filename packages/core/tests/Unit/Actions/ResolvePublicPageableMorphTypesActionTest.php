<?php

declare(strict_types=1);

use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Models\Page;
use Illuminate\Foundation\Auth\User;

it('returns aliases and model classes only for pageable morphs', function (): void {
    expect(ResolvePublicPageableMorphTypesAction::run())
        ->toContain('page', Page::class)
        ->not->toContain('user', User::class);
});
