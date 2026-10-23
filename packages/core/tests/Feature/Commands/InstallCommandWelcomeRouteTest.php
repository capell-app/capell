<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Enums\PackageScopeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallPostInstallOptionResolver;
use Capell\Core\Support\Install\WelcomeRouteInstaller;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeMigrationFilesystem;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeRunInstallAction;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        ->expectsConfirmation('Let Capell handle the homepage?', 'no')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Build production frontend assets now?', 'no')
        ->expectsConfirmation('Install Capell with these settings?', 'yes')
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
        ->expectsConfirmation('Build production frontend assets now?', 'no')
        ->expectsConfirmation('Install Capell with these settings?', 'yes')
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
        ->expectsConfirmation('Let Capell handle the homepage?', 'yes')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Build production frontend assets now?', 'no')
        ->expectsConfirmation('Install Capell with these settings?', 'yes')
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
        ->expectsConfirmation('Let Capell handle the homepage?', 'no')
        ->expectsConfirmation('Install AI / Agent Bridge developer tooling?', 'no')
        ->expectsConfirmation('Build production frontend assets now?', 'no')
        ->expectsConfirmation('Install Capell with these settings?', 'yes')
        ->expectsConfirmation('Would you like to star our repo on GitHub?', 'no')
        ->assertExitCode(Command::SUCCESS);

    expect($fake->capturedInput->installWelcomeRoute)->toBeFalse();

    require $routesPath;
    $route = Route::getRoutes()->match(Request::create('/'));
    expect($route->run()->name())->toBe('welcome')
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

    assertWelcomeRouteAbsent($routesPath);
    expect(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true');
});

it('preserves the capell controller home route when requested by the install step', function (): void {
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

    $original = file_get_contents($routesPath);

    expect($installer->hasRootRoute())->toBeTrue()
        ->and($installer->hasStockWelcomeRoute())->toBeFalse()
        ->and($installer->install())->toBeFalse()
        ->and(file_get_contents($routesPath))->toBe($original);
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

    assertWelcomeRouteAbsent($routesPath);
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

    assertWelcomeRouteAbsent($routesPath);
    expect(Route::getRoutes()->match(Request::create('/health'))->run())->toBe('ok');
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

    assertWelcomeRouteAbsent($routesPath);
});

function assertWelcomeRouteAbsent(string $path): void
{
    Route::setRoutes(new RouteCollection);
    require $path;
    expect(fn () => Route::getRoutes()->match(Request::create('/')))
        ->toThrow(NotFoundHttpException::class);
}

it('removes only the top level stock welcome statement and preserves grouped routes byte for byte', function (string $welcome): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    $group = <<<'PHP'
Route::prefix('_local/email-previews')->name('local.email-previews.')->group(function (): void {
    Route::get('/', [EmailPreviewController::class, 'index'])->name('index');


    Route::prefix('stock')->group(function (): void {
        Route::view('/', 'welcome');
    });
});
PHP;
    $before = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n" . $group . "\n\n";
    $after = "\n\nRoute::get('/health', fn () => 'ok');\n";
    file_put_contents($routesPath, $before . $welcome . $after);

    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->hasRootRoute())->toBeTrue()
        ->and($installer->hasStockWelcomeRoute())->toBeTrue()
        ->and($installer->install())->toBeTrue()
        ->and(file_get_contents($routesPath))->toBe($before . $after);

    $routes = patchedCoreWelcomeRoutes($routesPath);
    expect($routes->match(Request::create('/_local/email-previews/stock'))->defaults['view'])->toBe('welcome')
        ->and($routes->match(Request::create('/health'))->run())->toBe('ok')
        ->and($routes->match(Request::create('/_local/email-previews'))->getAction('controller'))->toBe('EmailPreviewController@index');
    expect(fn () => $routes->match(Request::create('/')))->toThrow(NotFoundHttpException::class);

    expect($installer->hasRootRoute())->toBeFalse()
        ->and($installer->hasStockWelcomeRoute())->toBeFalse()
        ->and($installer->install())->toBeFalse()
        ->and(file_get_contents($routesPath))->toBe($before . $after);
})->with([
    'closure' => "Route::get('/', function () { return view('welcome'); });",
    'static closure' => "Route::get('/', static function () { return view('welcome'); });",
    'arrow' => "Route::get('/', fn () => view('welcome'));",
    'static arrow' => 'Route::get("/", static fn () => view("welcome"));',
    'view' => "Route::view('/', 'welcome');",
    'named view' => "Route::view('/', 'welcome')->name('home');",
]);

it('ignores group relative root routes and leaves the file unchanged', function (): void {
    $routesPath = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($routesPath))) {
        mkdir(dirname($routesPath), 0755, true);
    }

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('_local/email-previews')->group(function (): void {
    Route::get('/', [EmailPreviewController::class, 'index'])->name('index');
    Route::view('/', 'welcome');
});
PHP;
    file_put_contents($routesPath, $content);
    $installer = resolve(WelcomeRouteInstaller::class);

    expect($installer->hasRootRoute())->toBeFalse()
        ->and($installer->canInstall())->toBeFalse()
        ->and($installer->hasStockWelcomeRoute())->toBeFalse()
        ->and($installer->install())->toBeFalse()
        ->and(file_get_contents($routesPath))->toBe($content);
});

function patchedCoreWelcomeRoutes(string $path): RouteCollection
{
    $router = Route::getFacadeRoot();
    $original = $router->getRoutes();
    $routes = new RouteCollection;
    $router->setRoutes($routes);
    try {
        require $path;

        return $routes;
    } finally {
        $router->setRoutes($original);
    }
}

it('keeps frontend root registration disabled when an accepted CLI choice leaves an application root', function (string $route): void {
    $path = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $content = "<?php\nuse Illuminate\\Support\\Facades\\Route;\n" . $route;
    file_put_contents($path, $content);
    file_put_contents(welcomeRouteTestEnvPath(), "CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false\n");
    $installer = resolve(WelcomeRouteInstaller::class);
    $messages = [];
    $record = function (string $message) use (&$messages): void {
        $messages[] = $message;
    };
    (new InstallPostInstallOptionResolver)->configureWelcomeRoute($installer, true, $record, $record);
    $installer->install();
    expect(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false')
        ->and($messages)->toBe([])
        ->and($installer->hasRootRoute())->toBeTrue();
    $expected = str_replace("Route::get('/', fn () => view('welcome'));", '', $content);
    expect(file_get_contents($path))->toBe($expected);
    $routes = patchedCoreWelcomeRoutes($path);
    expect($routes->match(Request::create('/'))->uri())->toBe('/');
})->with([
    'controller' => "Route::get('/', [HomeController::class, 'index']);",
    'view' => "Route::view('/', 'homepage');",
    'redirect' => "Route::redirect('/', '/health');",
    'middleware group' => "Route::middleware('auth')->group(function () { Route::get('/', [HomeController::class, 'index']); });",
    'stock plus custom root' => "Route::get('/', fn () => view('welcome'));\nRoute::redirect('/', '/health');",
]);

it('enables the frontend root only after the stock route has been removed', function (): void {
    $path = welcomeRouteTestRoutesPath();
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::view('/', 'welcome');");
    file_put_contents(welcomeRouteTestEnvPath(), "CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false\n");
    $installer = resolve(WelcomeRouteInstaller::class);
    $messages = [];
    $record = function (string $message) use (&$messages): void {
        $messages[] = $message;
    };
    (new InstallPostInstallOptionResolver)->configureWelcomeRoute($installer, true, $record, $record);
    expect(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=false')
        ->and($installer->hasStockWelcomeRoute())->toBeTrue();
    expect($installer->install())->toBeTrue()
        ->and($installer->hasRootRoute())->toBeFalse()
        ->and(file_get_contents(welcomeRouteTestEnvPath()))->toContain('CAPELL_FRONTEND_REGISTER_HOME_ROUTE=true')
        ->and($messages)->toBe([]);
});
