<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallInputFactory;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'capell-installer.bootstrap.secret' => str_repeat('a', 64),
        'capell-installer.bootstrap.expires_at' => now()->addMinutes(30)->timestamp,
    ]);
});

it('refuses every installer mutation without operator proof', function (string $routeName): void {
    $this->postJson(route('capell-installer.' . $routeName, ['installId' => (string) Str::uuid()]))->assertForbidden();
})->with(['store', 'run-step', 'cancel', 'destroy']);

it('refuses absent expired invalid and overlong bootstrap grants', function (string $case): void {
    if ($case === 'absent') {
        config(['capell-installer.bootstrap.secret' => null]);
    } elseif ($case === 'expired') {
        config(['capell-installer.bootstrap.expires_at' => now()->subSecond()->timestamp]);
    } elseif ($case === 'overlong') {
        config(['capell-installer.bootstrap.expires_at' => now()->addHours(2)->timestamp]);
    }

    $this->postJson(route('capell-installer.run-step'), [
        'bootstrap_secret' => $case === 'invalid' ? str_repeat('b', 64) : str_repeat('a', 64),
    ])->assertForbidden();
})->with(['absent', 'expired', 'invalid', 'overlong']);

it('carries operator proof between steps but expires and revokes it', function (): void {
    $this->postJson(route('capell-installer.run-step'), ['bootstrap_secret' => str_repeat('a', 64)])
        ->assertUnprocessable()->assertJsonValidationErrors('install_id');
    $this->postJson(route('capell-installer.run-step'), ['bootstrap_secret' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors('install_id');
    $this->travel(31)->minutes();
    $this->postJson(route('capell-installer.run-step'))->assertForbidden();
    $this->travelBack();
    config(['capell-installer.bootstrap.secret' => str_repeat('b', 64)]);
    $this->postJson(route('capell-installer.run-step'))->assertForbidden();
});

it('rejects fresh database installs through the browser', function (): void {
    $this->postJson(route('capell-installer.store'), [
        'bootstrap_secret' => str_repeat('a', 64),
        'fresh_install' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors('fresh_install');
});

it('never builds a destructive install from web input', function (): void {
    $input = resolve(InstallInputFactory::class)->fromWebInput([
        'site_url' => 'https://example.test',
        'language' => 'en',
        'new_user_name' => 'Operator',
        'new_user_email' => 'operator@example.test',
        'new_user_password' => 'password123',
        'fresh_install' => true,
        'package_selection_mode' => 'custom',
        'packages' => [],
    ]);

    expect($input->freshInstall)->toBeFalse();
});

it('does not expose the configured secret or flash it after validation failures', function (): void {
    $this->get(route('capell-installer.show'))
        ->assertOk()->assertSee('name="bootstrap_secret"', false)
        ->assertDontSee(str_repeat('a', 64));
    $this->post(route('capell-installer.store'), ['bootstrap_secret' => str_repeat('a', 64)])
        ->assertRedirect()->assertSessionMissing('_old_input.bootstrap_secret');
    expect(session('capell.installer.bootstrap_digest'))->toBe(hash('sha256', str_repeat('a', 64)));
});
