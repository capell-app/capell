<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Tests\Fixtures\Models\User;
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

it('does not disclose setup credentials or user records before bootstrap proof', function (): void {
    config([
        'capell-installer.admin_user.name' => 'Private operator',
        'capell-installer.admin_user.email' => 'private-operator@example.test',
        'capell-installer.admin_user.password' => 'private-setup-password',
    ]);
    User::factory()->createOne(['email' => 'existing-private@example.test']);

    $html = $this->get(route('capell-installer.show'))->assertOk()->content();
    foreach (['private-setup-password', 'private-operator@example.test', 'existing-private@example.test', 'id="install-form"'] as $privateValue) {
        expect(str_contains((string) $html, $privateValue))->toBeFalse();
    }
});

it('unlocks sensitive setup data only for a current bootstrap session', function (): void {
    config(['capell-installer.admin_user.email' => 'private-operator@example.test']);
    $this->post(route('capell-installer.unlock'), ['bootstrap_secret' => 'incorrect'])->assertForbidden();
    $this->post(route('capell-installer.unlock'), ['bootstrap_secret' => str_repeat('a', 64)])
        ->assertRedirect(route('capell-installer.show'));
    $html = $this->get(route('capell-installer.show'))->assertOk()->content();
    expect(str_contains((string) $html, 'private-operator@example.test'))->toBeTrue();

    config(['capell-installer.bootstrap.secret' => str_repeat('b', 64)]);
    $html = $this->get(route('capell-installer.show'))->assertOk()->content();
    expect(str_contains((string) $html, 'private-operator@example.test'))->toBeFalse();

    $this->post(route('capell-installer.unlock'), ['bootstrap_secret' => str_repeat('b', 64)])
        ->assertRedirect(route('capell-installer.show'));
    $this->travel(31)->minutes();
    $html = $this->get(route('capell-installer.show'))->assertOk()->content();
    expect(str_contains((string) $html, 'private-operator@example.test'))->toBeFalse();
});

it('does not build private page data for an unproved request', function (): void {
    app()->bind(BuildInstallerPageDataAction::class, fn (): never => throw new LogicException('Private page data must not be built.'));
    $this->get(route('capell-installer.show'))->assertOk();
});

it('allows bootstrap proof for installer removal after installation without permitting reinstallation', function (): void {
    config(['capell-installer.allow_reinstall' => false]);
    Site::factory()->createOne();
    InstallerInstallationState::forget();
    expect(InstallerInstallationState::capellIsInstalled())->toBeTrue();

    $this->post(route('capell-installer.unlock'), ['bootstrap_secret' => str_repeat('a', 64)])
        ->assertRedirect(route('capell-installer.show'));
    $this->get(route('capell-installer.show'))->assertOk()
        ->assertSee(__('capell-installer::installer.already_installed_heading'));
    $this->postJson(route('capell-installer.store'))->assertNotFound();
});
