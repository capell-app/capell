<?php

declare(strict_types=1);

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelPluginPatch;
use Capell\Tests\Support\GeneratedPhpFixture;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-admin-panel-plugin-patch-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('cannot patch a missing panel provider', function (): void {
    expect((new AdminPanelPluginPatch)->probe())->toBe(PatchStatus::Unsupported);
});

it('installs a working default Capell panel while preserving existing panel settings', function (string $chain): void {
    $path = writeAdminPanelPluginPatchProvider($chain);
    $patch = new AdminPanelPluginPatch;
    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();

    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->getId())->toBe('admin')
        ->and($panel->getPath())->toBe('workspace')
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->isDefault())->toBeTrue()
        ->and($panel->hasPlugin(CapellAdminPlugin::ID))->toBeTrue()
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
})->with([
    'stock panel' => [''],
    'existing default' => ['->default()'],
    'existing plugin' => ['->plugin(CapellAdminPlugin::make())'],
]);

it('preserves unrelated plugins when adding Capell integration', function (): void {
    $path = writeAdminPanelPluginPatchProvider('->default()->plugin(OtherPlugin::make())');
    $patch = new AdminPanelPluginPatch;
    $patch->apply();

    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->hasPlugin('existing-plugin'))->toBeTrue()
        ->and($panel->hasPlugin(CapellAdminPlugin::ID))->toBeTrue()
        ->and($panel->isDefault())->toBeTrue();
});

it('recognises a working configured panel and refuses to rewrite it', function (): void {
    $path = writeAdminPanelPluginPatchProvider('->default()->plugin(CapellAdminPlugin::make())');
    $original = File::get($path);
    $patch = new AdminPanelPluginPatch;
    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($original);
    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->hasPlugin(CapellAdminPlugin::ID))->toBeTrue();
});

it('preserves customised panel methods', function (string $body): void {
    $path = writeAdminPanelPluginPatchProvider('', $body);
    $original = File::get($path);
    $patch = new AdminPanelPluginPatch;
    expect($patch->probe())->toBe(PatchStatus::Customised)
        ->and(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($original);
})->with([
    'multiple statements' => ['$debug = config("app.debug"); return $panel->id("admin");'],
    'conditional panel' => ['if (config("app.debug")) { return $panel->id("admin"); } return $panel;'],
]);

it('exposes its install guide metadata', function (): void {
    $patch = new AdminPanelPluginPatch;
    expect($patch->id())->toBe('admin-panel-plugin-patch')
        ->and($patch->group())->toBe('providers')
        ->and($patch->defaultEnabled())->toBeTrue()
        ->and($patch->docUrl())->toBeNull()
        ->and($patch->reason())->toBeNull();
});

function writeAdminPanelPluginPatchProvider(string $chain, ?string $body = null): string
{
    $body ??= sprintf("return \$panel->id('admin')->path('workspace')->login()%s;", $chain);
    $path = base_path('app/Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Tests\Support\Fakes\OtherPanelPlugin as OtherPlugin;
use Filament\Panel;
use Filament\PanelProvider;
use Override;

class AdminPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel \$panel): Panel
    {
        {$body}
    }
}
PHP);

    return $path;
}
