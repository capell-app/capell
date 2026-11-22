<?php

declare(strict_types=1);

use Capell\Admin\Actions\Users\GrantPanelAccessAction;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Support\Diagnostics\Checks\AdminPanelUserRoleCheck;
use Capell\Admin\Support\Security\ShieldPanelUserDefault;
use Capell\Admin\Tests\Fixtures\Models\WebRegistrationUser;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;

/**
 * Settles the application as a stock install sees it: Shield's own default, no published host config.
 */
function bootAsStockInstall(): void
{
    config()->set('filament-shield.panel_user', ['enabled' => true, 'name' => 'panel_user']);

    new AdminServiceProvider(app())->registeringPackage();
}

function asWebRequest(Closure $callback): void
{
    $console = new ReflectionProperty(app(), 'isRunningInConsole');
    $original = $console->getValue(app());
    $console->setValue(app(), false);

    try {
        $callback();
    } finally {
        $console->setValue(app(), $original);
    }
}

it('does not give a user registered in a web request a login to the admin panel', function (): void {
    bootAsStockInstall();

    asWebRequest(function (): void {
        $user = WebRegistrationUser::query()->create([
            'name' => 'Customer',
            'email' => 'customer@example.test',
            'password' => 'password123',
        ]);

        expect($user->roles()->count())->toBe(0)
            ->and($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    });
});

it('admits a user an administrator chose through the grant action', function (): void {
    bootAsStockInstall();

    $user = WebRegistrationUser::query()->create([
        'name' => 'Invited',
        'email' => 'invited@example.test',
        'password' => 'password123',
    ]);

    GrantPanelAccessAction::run($user);

    expect(WebRegistrationUser::query()->whereKey($user->getKey())->firstOrFail()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();
});

it('keeps a host config that deliberately enables the panel user role', function (): void {
    $path = sys_get_temp_dir() . '/capell-shield-' . uniqid() . '.php';
    File::put($path, "<?php\nreturn ['panel_user' => ['enabled' => true, 'name' => 'panel_user']];\n");
    config()->set('filament-shield.panel_user.enabled', true);

    try {
        expect(resolve(ShieldPanelUserDefault::class)->apply($path))->toBeFalse()
            ->and(config('filament-shield.panel_user.enabled'))->toBeTrue();
    } finally {
        File::delete($path);
    }
});

it('applies the default when a published host config does not mention the panel user role', function (): void {
    $path = sys_get_temp_dir() . '/capell-shield-' . uniqid() . '.php';
    File::put($path, "<?php\nreturn ['super_admin' => ['enabled' => true]];\n");
    config()->set('filament-shield.panel_user.enabled', true);

    try {
        expect(resolve(ShieldPanelUserDefault::class)->apply($path))->toBeTrue()
            ->and(config('filament-shield.panel_user.enabled'))->toBeFalse();
    } finally {
        File::delete($path);
    }
});

it('fails Doctor with a next action when the panel user role is handed to every new user', function (): void {
    config()->set('filament-shield.panel_user.enabled', true);

    $result = (new AdminPanelUserRoleCheck)->check();

    expect($result->passed)->toBeFalse()
        ->and($result->severity)->toBe(DoctorCheckSeverity::Critical)
        ->and($result->remediation)->toContain('filament-shield.php');
});

it('passes Doctor when the panel user role is not handed out automatically', function (): void {
    config()->set('filament-shield.panel_user.enabled', false);

    expect((new AdminPanelUserRoleCheck)->check()->passed)->toBeTrue();
});
