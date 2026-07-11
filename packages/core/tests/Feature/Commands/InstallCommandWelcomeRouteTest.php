<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeRunInstallAction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    CapellCore::clearPackages();

    $testToken = getenv('TEST_TOKEN');
    $testToken = $testToken !== false ? $testToken : (string) getmypid();

    $sandboxPath = storage_path('framework/testing/welcome-route-' . $testToken);

    config([
        'capell.install.welcome_routes_web_path' => $sandboxPath . '/routes/web.php',
        'capell.install.welcome_env_path' => $sandboxPath . '/.env',
    ]);
});

afterEach(function (): void {
    CapellCore::clearPackages();

    $routesPath = welcomeRouteTestRoutesPath();

    if (file_exists($routesPath)) {
        unlink($routesPath);
    }

    $envPath = welcomeRouteTestEnvPath();
    if (file_exists($envPath)) {
        unlink($envPath);
    }

    $routesDirectory = dirname($routesPath);
    if (is_dir($routesDirectory)) {
        $routeFiles = scandir($routesDirectory);

        if ($routeFiles !== false && count($routeFiles) === 2) {
            rmdir($routesDirectory);
        }
    }
});

function welcomeRouteTestRoutesPath(): string
{
    return (string) config('capell.install.welcome_routes_web_path');
}

function welcomeRouteTestEnvPath(): string
{
    return (string) config('capell.install.welcome_env_path');
}

it('allows frontend installs when the application has already replaced the welcome route', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/versions/latest');
Route::get('/versions/latest', fn () => 'Latest demo');
PHP);

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
    ])
        ->expectsConfirmation('Remove existing home route?', 'no')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->callCount)->toBe(1)
        ->and(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false');
});

it('does not ask to remove a home route when the application has no root route', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
    ])
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeFalse();
});

it('asks to remove the stock Laravel welcome route when it exists', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
PHP);

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
    ])
        ->expectsConfirmation('Remove existing home route?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeTrue();
});

it('leaves the stock Laravel welcome route when removal is declined', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
PHP);

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
    ])
        ->expectsConfirmation('Remove existing home route?', 'no')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Would you like to run an npm build after this command completes?', 'no')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeFalse()
        ->and(file_get_contents($routesPath))->toContain("Route::get('/', fn () => view('welcome'));")
        ->and(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false');
});

it('passes the explicit welcome route install flag in non-interactive mode', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
        '--install-welcome-route' => true,
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeTrue();
});

it('passes the welcome route flag when a custom root route exists in non-interactive mode', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => 'Home');
PHP);

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
        '--install-welcome-route' => true,
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeTrue();
});

it('passes the welcome route flag when the capell home route already exists in non-interactive mode', function (): void {
    Storage::fake();
    app()->instance(MigrationFilesystemInterface::class, new FakeMigrationFilesystem);

    CapellCore::registerPackage(
        name: 'capell-app/frontend',
        path: realpath(__DIR__ . '/../../../../../tests/fixtures/install-package'),
    );
    CapellCore::getPackage('capell-app/frontend')->scopes = [PackageScopeEnum::Frontend];

    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Capell\Frontend\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', PageController::class)->name('home');
PHP);

    $fake = new FakeRunInstallAction;
    app()->instance(RunInstallAction::class, $fake);

    artisanCommand('capell:install', [
        '--packages' => 'capell-app/frontend',
        '--url' => 'https://demo.capell.app',
        '--name' => 'Demo Admin',
        '--email' => 'demo@example.com',
        '--password' => 'password123',
        '--clear-cache' => true,
        '--theme' => 'none',
        '--install-welcome-route' => true,
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeTrue();
});

it('does not write a welcome route when no home route exists', function (): void {
    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->install())->toBeFalse();

    expect(file_exists(welcomeRouteTestRoutesPath()))->toBeFalse();
});

it('removes the stock Laravel welcome route when requested by the install step', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
PHP);

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->install())->toBeTrue();

    expect(file_get_contents($routesPath))
        ->not->toContain("Route::get('/', fn () => view('welcome'));")
        ->toContain('use Illuminate\Support\Facades\Route;');
    expect(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true');
});

it('removes the capell home route when requested by the install step', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Capell\Frontend\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/', PageController::class)->name('home');
PHP);

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->install())->toBeTrue();

    expect(file_get_contents($routesPath))
        ->not->toContain("Route::get('/', PageController::class)->name('home');");
});

it('preserves existing route file headers when no removable home route exists', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

declare(strict_types=1);

PHP);

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->install())->toBeFalse();

    expect(file_get_contents($routesPath))
        ->toContain('declare(strict_types=1);')
        ->not->toContain("Route::get('/',");
});

it('detects and removes route view welcome routes while updating an existing env flag', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');
Route::get('/health', fn () => 'ok');
PHP);
    file_put_contents(welcomeRouteTestEnvPath(), "APP_NAME=Capell\nCAPELL_FRONTEND_REGISTER_HOME_ROUTE=false\n");

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->canInstall())->toBeTrue()
        ->and($installer->hasStockWelcomeRoute())->toBeTrue()
        ->and($installer->install())->toBeTrue();

    expect(file_get_contents($routesPath))
        ->not->toContain("Route::view('/', 'welcome')->name('home');")
        ->toContain("Route::get('/health', fn () => 'ok');");
    expect(file_get_contents(welcomeRouteTestEnvPath()))
        ->toContain('APP_NAME=Capell')
        ->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true');
});

it('detects function based stock welcome routes separately from custom home routes', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
PHP);

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->hasRootRoute())->toBeTrue()
        ->and($installer->hasStockWelcomeRoute())->toBeTrue()
        ->and($installer->install())->toBeTrue();

    expect(file_get_contents($routesPath))->not->toContain("return view('welcome');");
});
