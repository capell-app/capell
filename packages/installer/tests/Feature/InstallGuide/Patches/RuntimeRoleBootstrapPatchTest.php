<?php

declare(strict_types=1);

use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Installer\Support\InstallGuide\Patches\RuntimeRoleBootstrapPatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->testDirectory = sys_get_temp_dir() . '/capell-runtime-role-bootstrap-patch-' . uniqid();

    File::ensureDirectoryExists($this->testDirectory . '/bootstrap');
    $this->app->setBasePath($this->testDirectory);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->testDirectory);
});

it('configures the immutable runtime role before the stock Laravel application is returned', function (): void {
    File::put(base_path('bootstrap/app.php'), stockRuntimeRoleBootstrapApplication());

    $patch = new RuntimeRoleBootstrapPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $process = new Process([
        PHP_BINARY, '-r',
        'require $argv[1]; $app = require $argv[2];
        $app->bootstrapWith([' . LoadEnvironmentVariables::class . '::class]);
        echo json_encode($app->make(' . RuntimeRoleResolver::class . '::class)->role()->value, JSON_THROW_ON_ERROR);',
        dirname(__DIR__, 6) . '/vendor/autoload.php',
        base_path('bootstrap/app.php'),
    ], env: ['CAPELL_RUNTIME_ROLE' => 'public']);
    $process->mustRun();
    expect(json_decode($process->getOutput(), flags: JSON_THROW_ON_ERROR))->toBe(RuntimeRole::Public->value)
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('does not rewrite a customised application bootstrap', function (): void {
    File::put(base_path('bootstrap/app.php'), <<<'PHP'
<?php

use Illuminate\Foundation\Application;

$application = new Application(dirname(__DIR__));

return $application;
PHP);

    expect((new RuntimeRoleBootstrapPatch)->probe())->toBe(PatchStatus::Customised);
});

it('does not mistake a custom fluent builder for the stock Laravel bootstrap', function (): void {
    File::put(base_path('bootstrap/app.php'), <<<'PHP'
<?php

use App\Bootstrap\CustomBuilder;

return CustomBuilder::configure()
    ->withApplicationDefaults()
    ->create();
PHP);

    expect((new RuntimeRoleBootstrapPatch)->probe())->toBe(PatchStatus::Customised);
});

it('does not mistake an unrelated imported Application class for Laravel', function (): void {
    File::put(base_path('bootstrap/app.php'), <<<'PHP'
<?php

use App\Bootstrap\Application;

return Application::configure()
    ->withApplicationDefaults()
    ->create();
PHP);

    expect((new RuntimeRoleBootstrapPatch)->probe())->toBe(PatchStatus::Customised);
});

it('is registered for web and command-line installs regardless of selected packages', function (): void {
    expect(resolve(PatchRegistry::class)->get('runtime-role-bootstrap-patch'))
        ->toBeInstanceOf(RuntimeRoleBootstrapPatch::class);

    $patches = resolve(InstallPatchRegistry::class)->patchesFor(new InstallPatchContext(
        packageNames: [],
        hasFilamentAdminPanelProvider: false,
    ));

    expect(collect($patches)->pluck('patch')->map->id()->all())
        ->toContain('runtime-role-bootstrap-patch');
});

function stockRuntimeRoleBootstrapApplication(): string
{
    return <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP;
}
