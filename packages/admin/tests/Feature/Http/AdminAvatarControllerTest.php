<?php

declare(strict_types=1);

use Capell\Admin\Filament\AvatarProviders\InlineSvgAvatarProvider;
use Capell\Tests\Fixtures\Models\User;

it('serves the admin avatar fallback route without a 404', function (): void {
    test()->actingAsAdmin();

    $this->get('/admin/avatar/AS.svg')
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertSee('<svg', false)
        ->assertSee('AS');
});

it('uses inline SVG data URIs for default Filament avatars', function (): void {
    $user = User::factory()->make([
        'name' => 'Admin Smoke',
    ]);

    $avatar = (new InlineSvgAvatarProvider)->get($user);

    expect($avatar)
        ->toStartWith('data:image/svg+xml;utf8,')
        ->not->toContain('/admin/avatar/')
        ->and(urldecode($avatar))->toContain('AS');
});
