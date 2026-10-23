<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\RemoveWelcomeRoutePatch;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->testDir = sys_get_temp_dir() . '/capell-welcome-route-patch-test-' . uniqid();
    mkdir($this->testDir, 0755, true);
});

afterEach(function (): void {
    if (is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

it('probe_returns_unsupported_when_routes_web_does_not_exist', function (): void {
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Unsupported);
});

it('probe_returns_applicable_when_stock_welcome_block_present', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Applicable);
});

it('probe_returns_applicable_with_extra_whitespace_in_welcome_block', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get(   '/'   ,   function   (   )   {
    return    view(   'welcome'   )   ;
}   )   ;

Route::get('/home', function () {
    return view('home');
});
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Applicable);
});

it('probe_returns_applicable_when_home_named_stock_view_route_present', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')
    ->name('home');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
});

it('probe_returns_applicable_when_unnamed_view_welcome_route_present', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $routes = patchedWelcomeRoutes($routesPath);
    expect(array_map(fn (Illuminate\Routing\Route $route): string => $route->uri(), $routes->getRoutes()))->toBe(['dashboard']);
    expect($routes->match(Request::create('/dashboard'))->defaults['view'])->toBe('dashboard');
});

it('probe_returns_already_applied_when_stock_block_absent_and_no_root_route', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/api/data', function () {
    return response()->json(['data' => []]);
});
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::AlreadyApplied);
});

it('probe_returns_customised_when_custom_root_route_present', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('custom-home');
});

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Customised);
});

it('probe_returns_customised_when_root_route_with_different_handler_type', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;

Route::get('/', [HomeController::class, 'index']);

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Customised);
});

it('apply_removes_the_real_stock_welcome_route_file_and_preserves_other_handlers', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});



Route::get('/dashboard', function () {
    return response()->json(['status' => 'ok']);
});
Route::post('/api/data', function () {
    return response()->json(['data' => []]);
});
PHP);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $routes = patchedWelcomeRoutes($routesPath);
    expect(array_map(fn (Illuminate\Routing\Route $route): string => $route->uri(), $routes->getRoutes()))->toBe(['dashboard', 'api/data']);
    $health = $routes->match(Request::create('/dashboard'));
    $data = $routes->match(Request::create('/api/data', Symfony\Component\HttpFoundation\Request::METHOD_POST));
    expect($health->run()->getData(true))->toBe(['status' => 'ok'])
        ->and($data->run()->getData(true))->toBe(['data' => []]);

});

it('apply_rejects_custom_root_routes_without_mutating_application_routes', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
PHP;

    file_put_contents($routesPath, $content);
    $this->app->setBasePath($this->testDir);

    expect(fn (): null => (new RemoveWelcomeRoutePatch)->apply())
        ->toThrow(RuntimeException::class, 'Cannot apply patch when status is: customised')
        ->and(file_get_contents($routesPath))->toBe($content);
});

it('patch_has_correct_metadata', function (): void {
    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->id())->toBe('remove-welcome-route-patch');
    expect($patch->group())->toBe('routes');
    expect($patch->defaultEnabled())->toBe(true);
    expect($patch->label())->toBeString();
    expect($patch->description())->toBeString();
    expect($patch->docUrl())->toBeNull();
});

function patchedWelcomeRoutes(string $path): RouteCollection
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

it('preserves grouped routes while removing only the top level stock statement', function (string $welcome): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);
    $this->app->setBasePath($this->testDir);

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
    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();

    expect(file_get_contents($routesPath))->toBe($before . $after)
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
    $routes = patchedWelcomeRoutes($routesPath);
    expect($routes->match(Request::create('/_local/email-previews/stock'))->defaults['view'])->toBe('welcome')
        ->and($routes->match(Request::create('/health'))->run())->toBe('ok')
        ->and($routes->match(Request::create('/_local/email-previews'))->getAction('controller'))->toBe('EmailPreviewController@index');
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'Cannot apply patch when status is: already_applied');
    expect(file_get_contents($routesPath))->toBe($before . $after);
})->with([
    'closure' => "Route::get('/', function () { return view('welcome'); });",
    'static closure' => "Route::get('/', static function () { return view('welcome'); });",
    'arrow' => "Route::get('/', fn () => view('welcome'));",
    'static arrow' => 'Route::get("/", static fn () => view("welcome"));',
    'view' => "Route::view('/', 'welcome');",
]);

it('does not classify grouped welcome routes as an application root route', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);
    $this->app->setBasePath($this->testDir);
    $content = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('preview')->group(function (): void {
    Route::get('/', fn () => view('welcome'));
    Route::get('/', function () { return view('welcome'); });
    Route::view('/', 'welcome');
});
PHP;
    file_put_contents($routesPath, $content);
    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(file_get_contents($routesPath))->toBe($content);
});

it('preserves a customised root using an imported helper after interpolation', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);
    $this->app->setBasePath($this->testDir);
    $content = <<<'PHP_SOURCE'
<?php
use Illuminate\Support\Facades\Route;
use function strtoupper as view;
$a = '';
$x = "}$a";
Route::get('/', fn () => view('welcome'));
PHP_SOURCE;
    file_put_contents($routesPath, $content);
    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(file_get_contents($routesPath))->toBe($content)
        ->and(patchedWelcomeRoutes($routesPath)->match(Request::create('/'))->run())->toBe('WELCOME');
});

it('classifies executed callbacks and parenthesised route mutations without editing them', function (string $source, bool $root): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);
    $this->app->setBasePath($this->testDir);
    $content = "<?php\nuse Illuminate\\Support\\Facades\\Route;\n" . $source;
    file_put_contents($routesPath, $content);
    $patch = new RemoveWelcomeRoutePatch;

    expect(array_any(patchedWelcomeRoutes($routesPath)->getRoutes(), static fn (Illuminate\Routing\Route $route): bool => $route->uri() === '/'))->toBe($root)
        ->and($patch->probe())->toBe($root ? PatchStatus::Customised : PatchStatus::AlreadyApplied);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class)
        ->and(file_get_contents($routesPath))->toBe($content);
})->with([
    'assigned callback' => ["\$registerHome = function () { Route::get('/', fn () => 'custom'); }; Route::group([], \$registerHome);", true],
    'explicit invocation' => ["(function () { Route::get('/', fn () => 'custom'); })->__invoke();", true],
    'parenthesised root mutation' => ["(Route::get('/health', fn () => 'custom'))->setUri('/');", true],
    'parenthesised prefix' => ["(Route::get('/', fn () => 'custom'))->prefix('admin');", false],
]);
