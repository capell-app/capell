<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\RootRouteEditor;
use Capell\Core\Tests\Support\Patching\RootRouteEditorCustomRouter;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

it('leaves custom and nested route statements untouched', function (string $source, bool $hasRoot): void {
    $content = "<?php\n" . $source;
    $editor = new RootRouteEditor($content);

    expect($editor->hasRootRoute())->toBe($hasRoot)
        ->and($editor->hasStockWelcomeRoute())->toBeFalse()
        ->and($editor->removeStockWelcomeRoutes())->toBe($content);
})->with([
    'controller' => ["Route::get('/', [HomeController::class, 'index'])->name('home');", true],
    'custom closure' => ["Route::get('/', function () { audit(); return view('welcome'); });", true],
    'custom arrow' => ["Route::get('/', fn () => view('homepage'));", true],
    'data passed to view' => ["Route::view('/', 'welcome', ['title' => 'Hello']);", true],
    'custom name' => ["Route::view('/', 'welcome')->name('preview');", true],
    'post' => ["Route::post('/', [HomeController::class, 'store']);", true],
    'match' => ["Route::match(['get', 'post'], '/', [HomeController::class, 'index']);", true],
    'non-root match' => ["Route::match(['get', 'post'], '/health', fn () => 'ok');", false],
    'controller prefix index' => ["Route::prefix('preview')->group(function () { Route::get('/', [HomeController::class, 'index']); });", false],
    'middleware group' => ["Route::middleware('auth')->group(function () { Route::view('/', 'welcome'); });", true],
    'comment' => ["// Route::get('/', fn () => view('welcome'));", false],
    'string' => [' $example = "Route::view(\'/\', \'welcome\');";', false],
    'conditional block' => ["if (true) { Route::view('/', 'welcome'); }", true],
    'alternative block' => ["if (true): echo 'preview'; Route::view('/', 'welcome'); endif;", true],
    'nested alternative blocks' => ["switch (true): case true: if (true): echo 'preview'; endif; Route::view('/', 'welcome'); endswitch;", true],
    'function' => ["function preview() { Route::view('/', 'welcome'); }", false],
]);

it('preserves lexical context and removes multiple stock routes without reformatting', function (): void {
    $before = <<<'PHP'
<?php
// Route::view('/', 'welcome');
$example = <<<'EXAMPLE'
Route::view('/', 'welcome');
EXAMPLE;
function preview() { return "{"; }

PHP;
    $first = '\\' . Route::class . "::get('/', static fn () => view('welcome'));";
    $middle = "\n\n\nRoute::get('/health', fn () => 'ok');\n\n";
    $second = "Route /* stock */ :: get('/', function () { return view('welcome'); });";
    $after = "\n// Keep this footer.\n";
    $editor = new RootRouteEditor($before . $first . $middle . $second . $after);

    expect($editor->hasRootRoute())->toBeTrue()
        ->and($editor->hasStockWelcomeRoute())->toBeTrue()
        ->and($editor->removeStockWelcomeRoutes())->toBe($before . $middle . $after);

    $again = new RootRouteEditor($editor->removeStockWelcomeRoutes());
    expect($again->hasRootRoute())->toBeFalse()
        ->and($again->removeStockWelcomeRoutes())->toBe($before . $middle . $after);
});

it('rejects malformed PHP rather than editing a partial route statement', function (): void {
    expect(fn (): RootRouteEditor => new RootRouteEditor("<?php Route::view('/', 'welcome'); syntax error"))
        ->toThrow(ParseError::class);
});

it('resolves facade and helper symbols before removing stock routes', function (string $source, bool $root, bool $stock): void {
    $content = "<?php\n" . $source;
    $editor = new RootRouteEditor($content);
    expect($editor->hasRootRoute())->toBe($root)
        ->and($editor->hasStockWelcomeRoute())->toBe($stock);
    if (! $stock) {
        expect($editor->removeStockWelcomeRoutes())->toBe($content);
    } else {
        expect(new RootRouteEditor($editor->removeStockWelcomeRoutes())->hasRootRoute())->toBeFalse();
    }
})->with([
    'declared global class' => ["class Route { public static function get(\$uri, \$action) {} } Route::get('/', fn () => view('welcome'));", false, false],
    'declared global helper' => ["function view(\$name) { return 'custom'; } Route::get('/', fn () => view('welcome'));", true, false],
    'custom class' => ["use App\\Services\\Route; Route::get('/', fn () => view('welcome'));", false, false],
    'custom function' => ["use Illuminate\\Support\\Facades\\Route; use function App\\Rendering\\home as view; Route::get('/', fn () => view('welcome'));", true, false],
    'facade alias' => ["use Illuminate\\Support\\Facades\\Route as R; R::get('/', fn () => view('welcome'));", true, true],
    'grouped facade alias' => ["use Illuminate\\Support\\Facades\\{Route as R, Cache}; R::view('/', 'welcome');", true, true],
    'grouped custom class' => ["use App\\Services\\{Route, Cache}; Route::view('/', 'welcome');", false, false],
    'grouped custom helper' => ["use function App\\Rendering\\{home as view, other}; Route::get('/', fn () => view('welcome'));", true, false],
    'mixed grouped helper' => ["use App\\Rendering\\{Thing, function home as view}; Route::get('/', fn () => view('welcome'));", true, false],
    'global helper alias' => ["use function view as render; Route::get('/', fn () => render('welcome'));", true, true],
    'fully qualified helper' => ["Route::get('/', fn () => \\view('welcome'));", true, true],
    'namespace custom class' => ["namespace App; Route::get('/', fn () => view('welcome'));", false, false],
    'namespace ambiguous helper' => ["namespace App; use Illuminate\\Support\\Facades\\Route; Route::get('/', fn () => view('welcome'));", true, false],
    'namespace explicit helper' => ["namespace App; use Illuminate\\Support\\Facades\\Route as R; R::get('/', fn () => \\view('welcome'));", true, true],
    'namespace shadowed helper' => ["namespace App; use Illuminate\\Support\\Facades\\Route; function view(\$name) { return 'custom'; } Route::get('/', fn () => view('welcome'));", true, false],
    'bracketed namespaces' => ["namespace App { use App\\Services\\Route; Route::view('/', 'welcome'); } namespace { use Illuminate\\Support\\Facades\\Route as R; R::view('/', 'welcome'); }", true, true],
]);

it('detects executable roots separately from safe removal candidates', function (string $source, bool $root): void {
    $content = "<?php\nuse Illuminate\\Support\\Facades\\Route as R;\n" . $source;
    $editor = new RootRouteEditor($content);
    expect($editor->hasRootRoute())->toBe($root)
        ->and($editor->hasStockWelcomeRoute())->toBeFalse()
        ->and($editor->removeStockWelcomeRoutes())->toBe($content);
    Route::setRoutes(new RouteCollection);
    eval(substr($content, 5));
    $registered = array_any(Route::getRoutes()->getRoutes(), static fn (Illuminate\Routing\Route $route): bool => $route->uri() === '/');
    expect($registered)->toBe($root);
})->with([
    'assigned group callback' => ["\$routes = function () { R::get('/', fn () => 'custom'); }; R::group([], \$routes);", true],
    'assigned arrow group router' => ["\$routes = fn (\$router) => \$router->get('/', fn () => 'custom'); R::group([], \$routes);", true],
    'assigned prefixed callback' => ["\$routes = function () { R::get('/', fn () => 'custom'); }; R::prefix('admin')->group(\$routes);", false],
    'explicit closure invocation' => ["(function () { R::get('/', fn () => 'custom'); })->__invoke();", true],
    'explicit arrow invocation' => ["(fn () => R::get('/', fn () => 'custom'))->__invoke();", true],
    'assigned closure invocation' => ["\$routes = function () { R::get('/', fn () => 'custom'); }; \$routes();", true],
    'assigned explicit invocation' => ["\$routes = fn () => R::get('/', fn () => 'custom'); \$routes->__invoke();", true],
    'call_user_func closure' => ["call_user_func(function () { R::get('/', fn () => 'custom'); });", true],
    'call_user_func assigned callback' => ["\$routes = fn () => R::get('/', fn () => 'custom'); call_user_func(\$routes);", true],
    'call_user_func_array callback' => ["\$routes = fn () => R::get('/', fn () => 'custom'); call_user_func_array(\$routes, []);", true],
    'named registration function' => ["function rootRouteEditorRegisterHome() { R::get('/', fn () => 'custom'); } rootRouteEditorRegisterHome();", true],
    'local facade router' => ["\$router = R::getFacadeRoot(); \$router->get('/', fn () => 'custom');", true],
    'local container router' => ["\$router = app('router'); \$router->get('/', fn () => 'custom');", true],
    'local resolved router' => ['$router = resolve(' . Router::class . '::class); $router->get(\'/\', fn () => \'custom\');', true],
    'parenthesised set root URI' => ["(R::get('/health', fn () => 'custom'))->setUri('/');", true],
    'parenthesised prefix' => ["(R::get('/', fn () => 'custom'))->prefix('admin');", false],
    'nested parenthesised mutation' => ["((R::get('/health', fn () => 'custom'))->setUri('/'))->prefix('admin');", false],
    'parenthesised resource filter' => ["(R::resource('/', HomeController::class))->only('show');", false],
    'callback array lookup' => ["\$callbacks = ['home' => fn () => R::get('/', fn () => 'custom')]; R::group([], \$callbacks['home']);", true],
    'named group callback' => ['function rootRouteEditorGroupHome($router) { $router->get(\'/\', fn () => \'custom\'); } R::group([], ' . Closure::class . "::fromCallable('rootRouteEditorGroupHome'));", true],
    'named forward registration' => ["rootRouteEditorForwardHome(); function rootRouteEditorForwardHome() { R::get('/', fn () => 'custom'); }", true],
    'second named registration' => ["function rootRouteEditorNamespaceHome() { R::get('/', fn () => 'custom'); } rootRouteEditorNamespaceHome();", true],
    'call_user_func named callback' => ["function rootRouteEditorIndirectHome() { R::get('/', fn () => 'custom'); } call_user_func('rootRouteEditorIndirectHome');", true],
    'named first class group callback' => ["function rootRouteEditorFirstClass(\$router) { \$router->get('/', fn () => 'custom'); } R::group([], rootRouteEditorFirstClass(...));", true],
    'prefixed assigned router callback' => ["\$routes = fn (\$router) => \$router->get('/', fn () => 'custom'); R::prefix('admin')->group(callback: \$routes);", false],
    'local router alias' => ["\$router = R::getFacadeRoot(); \$alias = \$router; \$alias->get('/', fn () => 'custom');", true],
    'deferred named function' => ["function rootRouteEditorDeferredHome() { R::get('/', fn () => 'custom'); }", false],
    'callback with deferred nested route' => ["\$routes = function () { \$later = fn () => R::get('/', fn () => 'custom'); }; R::group([], \$routes);", false],
    'assigned non-root callback' => ["\$routes = fn () => R::get('/health', fn () => 'custom'); \$routes();", false],
    'call_user_func non-root callback' => ["call_user_func(fn () => R::get('/health', fn () => 'custom'));", false],
    'callback reassignment' => ["\$routes = fn () => R::get('/', fn () => 'custom'); \$routes = fn () => R::get('/health', fn () => 'custom'); \$routes();", false],
    'parenthesised registrar prefix' => ["(R::prefix('admin'))->group(fn () => R::get('/', fn () => 'custom'));", false],
    'parenthesised reset URI' => ["((R::get('/', fn () => 'custom'))->prefix('admin'))->setUri('/');", true],
    'parenthesised assigned callback' => ["\$routes = (static fn () => R::get('/', fn () => 'custom')); (\$routes)();", true],
    'named router argument' => ["function rootRouteEditorRouterArgument(\$router) { \$router->get('/', fn () => 'custom'); } rootRouteEditorRouterArgument(app('router'));", true],
    'invoked router argument' => ["(function (\$router) { \$router->get('/', fn () => 'custom'); })(R::getFacadeRoot());", true],
    'call_user_func router argument' => ["call_user_func(fn (\$router) => \$router->get('/', fn () => 'custom'), app('router'));", true],
    'call_user_func_array router argument' => ["call_user_func_array(fn (\$router) => \$router->get('/', fn () => 'custom'), [app('router')]);", true],
    'local container make router' => ["\$router = app()->make('router'); \$router->get('/', fn () => 'custom');", true],
    'deferred first class function' => ["function rootRouteEditorDeferredCallable() { R::get('/', fn () => 'custom'); } \$later = rootRouteEditorDeferredCallable(...);", false],
    'deferred first class closure' => ["\$register = fn () => R::get('/', fn () => 'custom'); \$later = \$register(...);", false],
    'deferred first class facade' => ['$later = R::get(...);', false],
    'deferred first class callback executor' => ['$later = call_user_func(...);', false],
    'deferred first class URI mutation' => ["\$later = R::get('/health', fn () => 'custom')->setUri(...);", false],
    'resource index' => ["R::resource('/', HomeController::class)->only('index');", true],
    'api resource store' => ["R::apiResource('/', HomeController::class)->only('store');", true],
    'resource without root actions' => ["R::resource('/', HomeController::class)->only('show');", false],
    'resource except root actions' => ["R::resource('/', HomeController::class)->except(['index', 'store']);", false],
    'dynamic resource only' => ["\$methods = ['index']; R::resource('/', HomeController::class)->only(\$methods);", true],
    'dynamic resource option override' => ["\$methods = ['index']; R::resource('/', HomeController::class, ['only' => ['show']])->only(\$methods);", true],
    'nested resource options' => ["R::resource('/', HomeController::class, ['names' => ['only' => 'show']]);", true],
    'creatable singleton options' => ["R::singleton('/', HomeController::class, ['creatable' => true, 'only' => ['store']]);", true],
    'replaced resource options' => ["R::resource('/', HomeController::class, ['only' => ['show']])->only('index');", true],
    'replaced resource except' => ["R::resource('/', HomeController::class)->except(['index', 'store'])->except('show');", true],
    'api resources map' => ["R::apiResources(['/' => HomeController::class]);", true],
    'non-root resources map' => ["R::resources(['admin' => HomeController::class]);", false],
    'singleton without destroy' => ["R::singleton('/', HomeController::class)->only('destroy');", false],
    'destroyable singleton' => ["R::singleton('/', HomeController::class)->destroyable()->only('destroy');", true],
    'creatable singleton' => ["R::singleton('/', HomeController::class)->creatable()->only('store');", true],
    'group arrow router' => ["R::group([], fn (\$router) => \$router->get('/', fn () => 'custom'));", true],
    'deferred nested closure' => ["(function () { \$later = function () { R::get('/', fn () => 'custom'); }; })();", false],
    'literal closing brace in group' => ['R::group([], function () { $a = ""; $x = "}$a"; R::get("/", fn () => "custom"); });', true],
    'resource options' => ["R::resource('/', HomeController::class, ['only' => ['show']]);", false],
    'resources map' => ["R::resources(['/' => HomeController::class]);", true],
    'singleton show' => ["R::singleton('/', HomeController::class)->only('show');", true],
    'group router' => ["R::group([], function (\\Illuminate\\Routing\\Router \$router) { \$router->get('/', fn () => 'custom'); });", true],
    'prefixed group router' => ["R::prefix('admin')->group(function (\$router) { \$router->get('/', fn () => 'custom'); });", false],
    'nested router group' => ["R::group([], function (\$router) { \$router->middleware('auth')->group(function (\$nested) { \$nested->get('/', fn () => 'custom'); }); });", true],
    'invoked closure' => ["(function () { R::get('/', fn () => 'custom'); })();", true],
    'invoked static closure' => ["(static function () { R::get('/', fn () => 'custom'); })();", true],
    'invoked arrow' => ["(fn () => R::get('/', fn () => 'custom'))();", true],
    'set root URI' => ["R::get('/health', fn () => 'custom')->setUri('/');", true],
    'replace root URI' => ["R::get('/', fn () => 'custom')->setUri('/health');", false],
    'prefix after registration' => ["R::get('/', fn () => 'custom')->prefix('admin');", false],
    'replace fluent prefix' => ["R::get('/', fn () => 'custom')->prefix('admin')->prefix('');", false],
    'reset prefixed URI' => ["R::prefix('admin')->get('/health', fn () => 'custom')->setUri('/');", true],
    'interpolation in group' => ['R::prefix("admin")->group(function () { $a = ""; $x = "{$a}{"; R::get("/", fn () => "custom"); });', false],
    'middleware chain' => ["R::middleware('auth')->get('/', fn () => 'custom');", true],
    'name chain' => ["R::name('home')->get('/', fn () => 'custom');", true],
    'middleware group' => ["R::middleware('auth')->group(function () { R::get('/', fn () => 'custom'); });", true],
    'array group' => ["R::group(['middleware' => 'auth'], function () { R::get('/', fn () => 'custom'); });", true],
    'arrow group' => ["R::middleware('auth')->group(fn () => R::get('/', fn () => 'custom'));", true],
    'nested groups' => ["R::middleware('auth')->group(function () { R::name('home')->group(function () { R::get('/', fn () => 'custom'); }); });", true],
    'prefix group' => ["R::prefix('preview')->group(function () { R::get('/', fn () => 'custom'); });", false],
    'nested attribute prefix key' => ["R::group(['where' => ['prefix' => '.+']], function () { R::get('/', fn () => 'custom'); });", true],
    'replaced array prefix' => ["R::group(['prefix' => 'preview', 'prefix' => ''], function () { R::get('/', fn () => 'custom'); });", true],
    'array prefix group' => ["R::group(['prefix' => 'preview'], function () { R::get('/', fn () => 'custom'); });", false],
    'nested prefix group' => ["R::prefix('preview')->group(function () { R::middleware('auth')->group(function () { R::get('/', fn () => 'custom'); }); });", false],
    'empty prefix' => ["R::prefix('')->group(function () { R::get('/', fn () => 'custom'); });", true],
    'named arguments' => ["R::get(action: fn () => 'custom', uri: '/');", true],
    'replaced chain prefix' => ["R::prefix('preview')->prefix('')->get('/', fn () => 'custom');", true],
    'slash prefix' => ["R::prefix('/')->group(function () { R::get('/', fn () => 'custom'); });", true],
    'nested prefix in plain group' => ["R::middleware('auth')->group(function () { R::prefix('preview')->group(function () { R::get('/', fn () => 'custom'); }); });", false],
    'variable root uri' => ["\$uri = '/'; R::get(\$uri, fn () => 'custom');", true],
    'parenthesised root uri' => ["R::get(('/'), fn () => 'custom');", true],
    'empty uri' => ["R::get('', fn () => 'custom');", true],
    'escaped root uri' => ["R::get(\"\\x2f\", fn () => 'custom');", true],
    'assigned registration' => ["\$home = R::get('/', fn () => 'custom');", true],
    'addRoute' => ["R::addRoute('GET', '/', fn () => 'custom');", true],
    'named match' => ["R::match(action: fn () => 'custom', uri: '/', methods: ['GET']);", true],
    'deferred closure' => ["\$later = function () { R::get('/', fn () => 'custom'); };", false],
    'deferred arrow' => ["\$later = fn () => R::get('/', fn () => 'custom');", false],
    'non-root controller alias' => ["R::get('/health', [HomeController::class, 'index']);", false],
    'controller alias' => ["R::get('/', [HomeController::class, 'index']);", true],
    'redirect alias' => ["R::redirect('/', '/health');", true],
    'view alias with data' => ["R::view('/', 'welcome', ['title' => 'custom']);", true],
]);

it('preserves a handler using an imported custom view function and its output', function (): void {
    $source = <<<'PHP_SOURCE'
<?php
use Illuminate\Support\Facades\Route;
use function strtoupper as view;
Route::get('/', fn () => view('welcome'));
PHP_SOURCE;
    $edited = new RootRouteEditor($source)->removeStockWelcomeRoutes();
    expect($edited)->toBe($source);
    foreach ([$source, $edited] as $content) {
        Route::setRoutes(new RouteCollection);
        eval(substr($content, 5));
        expect(Route::getRoutes()->match(Request::create('/'))->run())->toBe('WELCOME');
    }
});

it('preserves imported custom calls after interpolated string braces', function (string $imports, string $call, bool $root): void {
    $source = "<?php\n" . $imports . <<<'PHP_SOURCE'

$a = '';
$x = "}$a";
PHP_SOURCE;
    $source .= "\n" . $call;
    $editor = new RootRouteEditor($source);
    expect($editor->hasRootRoute())->toBe($root)
        ->and($editor->hasStockWelcomeRoute())->toBeFalse()
        ->and($editor->removeStockWelcomeRoutes())->toBe($source);

    foreach ([$source, $editor->removeStockWelcomeRoutes()] as $content) {
        RootRouteEditorCustomRouter::$calls = 0;
        Route::setRoutes(new RouteCollection);
        eval(substr($content, 5));
        if ($root) {
            expect(Route::getRoutes()->match(Request::create('/'))->run())->toBe('WELCOME');
        } else {
            expect(RootRouteEditorCustomRouter::$calls)->toBe(1);
        }
    }
})->with([
    'custom class' => ['use Capell\\Core\\Tests\\Support\\Patching\\RootRouteEditorCustomRouter as Route;', "Route::get('/', fn () => view('welcome'));", false],
    'custom helper' => ['use Illuminate\\Support\\Facades\\Route; use function strtoupper as view;', "Route::get('/', fn () => view('welcome'));", true],
]);

it('treats unavailable executing group callbacks as possible roots', function (string $source): void {
    $content = "<?php\n" . $source;
    $editor = new RootRouteEditor($content);

    expect($editor->hasRootRoute())->toBeTrue()
        ->and($editor->hasStockWelcomeRoute())->toBeFalse()
        ->and($editor->removeStockWelcomeRoutes())->toBe($content);
})->with([
    'callback variable' => 'Route::group([], $unknown);',
    'callback factory' => 'Route::middleware("auth")->group(makeApplicationRoutes());',
    'included routes' => 'Route::group([], __DIR__ . "/application.php");',
]);

it('resolves executed registration functions in their namespace', function (): void {
    $content = <<<'PHP_SOURCE'
<?php
namespace RootRouteEditorFixtures;
use Illuminate\Support\Facades\Route as R;
registerHome();
function registerHome() { R::get('/', fn () => 'custom'); }
PHP_SOURCE;
    $editor = new RootRouteEditor($content);
    Route::setRoutes(new RouteCollection);
    eval(substr($content, 5));

    expect($editor->hasRootRoute())->toBeTrue()
        ->and($editor->removeStockWelcomeRoutes())->toBe($content)
        ->and(Route::getRoutes()->match(Request::create('/'))->run())->toBe('custom');
});

it('conservatively detects extension routes and required route files without removing them', function (string $source, bool $root): void {
    $content = "<?php\n" . $source;
    $editor = new RootRouteEditor($content);

    expect($editor->hasRootRoute())->toBe($root)
        ->and($editor->hasStockWelcomeRoute())->toBeFalse()
        ->and($editor->removeStockWelcomeRoutes())->toBe($content);
})->with([
    'inertia root' => ["Route::inertia('/', 'Home');", true],
    'livewire root' => ["Route::livewire('/', Home::class);", true],
    'custom root macro' => ["Route::landing('/', Home::class);", true],
    'named macro URI' => ["Route::landing(component: Home::class, uri: '/');", true],
    'non-root macro' => ["Route::inertia('/dashboard', 'Dashboard');", false],
    'prefixed macro' => ["Route::prefix('admin')->livewire('/', Home::class);", false],
    'deferred macro' => ["\$later = fn () => Route::inertia('/', 'Home');", false],
    'first class macro' => ['Route::inertia(...);', false],
    'Volt root' => ["use Livewire\\Volt\\Volt; Volt::route('/', 'home');", true],
    'Volt alias' => ["use Livewire\\Volt\\Volt as V; V::route('/', 'home');", true],
    'Volt non-root' => ["use Livewire\\Volt\\Volt; Volt::route('/dashboard', 'dashboard');", false],
    'unrelated Volt' => ["use App\\Volt; Volt::route('/', 'home');", false],
    'required routes' => ['require __DIR__ . "/home.php";', true],
    'required once routes' => ['require_once __DIR__ . "/home.php";', true],
    'included routes' => ['include __DIR__ . "/home.php";', true],
    'dynamic required routes' => ['require $routes;', true],
    'deferred required routes' => ['function later() { require __DIR__ . "/home.php"; }', false],
    'Breeze auth routes' => ["require __DIR__.'/auth.php';", false],
    'parenthesised Breeze auth routes' => ["require (__DIR__ . '/auth.php');", false],
    'unknown auth file' => ["require '/custom/auth.php';", true],
    'dynamic auth path' => ["require \$directory . '/auth.php';", true],
    'middleware attribute' => ["Route::middleware('/')->group(fn () => Route::get('/health', fn () => 'ok'));", false],
]);

it('tracks extension roots after removing only the stock welcome route', function (string $source, bool $root): void {
    $content = "<?php\n" . $source;
    $editor = new RootRouteEditor($content . ('
\\' . Route::class . "::view('/', 'welcome');"));
    $edited = $editor->removeStockWelcomeRoutes();

    expect($editor->hasStockWelcomeRoute())->toBeTrue()
        ->and($edited)->toBe($content . "\n")
        ->and(new RootRouteEditor($edited)->hasStockWelcomeRoute())->toBeFalse()
        ->and(new RootRouteEditor($edited)->hasRootRoute())->toBe($root);
})->with([
    'Volt fully qualified' => ["\\Livewire\\Volt\\Volt::route('/', 'home');", true],
    'Volt grouped alias' => ["use Livewire\\Volt\\{Volt as V}; V::route('/', 'home');", true],
    'Volt named URI' => ["use Livewire\\Volt\\Volt; Volt::route(componentName: 'home', uri: '/');", true],
    'Volt dynamic URI' => ["use Livewire\\Volt\\Volt; Volt::route(\$uri, 'home');", true],
    'Volt prefixed route' => ["use Livewire\\Volt\\Volt; Volt::route('/', 'home')->prefix('admin');", false],
    'Volt reset URI' => ["use Livewire\\Volt\\Volt; Volt::route('/dashboard', 'home')->name('home')->setUri('/');", true],
    'Volt reset inside group' => ["use Livewire\\Volt\\Volt; Route::prefix('admin')->group(fn () => Volt::route('/dashboard', 'home')->setUri('/'));", true],
    'Volt group prefix' => ["use Livewire\\Volt\\Volt; Route::prefix('admin')->group(fn () => Volt::route('/', 'home'));", false],
    'Volt deferred' => ["use Livewire\\Volt\\Volt; \$later = fn () => Volt::route('/', 'home');", false],
    'Volt first class callable' => ['use Livewire\\Volt\\Volt; $later = Volt::route(...);', false],
    'Volt configuration' => ["use Livewire\\Volt\\Volt; Volt::mount(['/']);", false],
    'Volt local shadow' => ["namespace Livewire\\Volt; class Volt { public static function route(\$uri, \$name) {} } Volt::route('/', 'home');", false],
    'macro no arguments' => ['Route::homepage();', true],
    'macro non-URI first argument' => ["Route::homepage('HomeController');", true],
    'macro other named argument' => ['Route::homepage(component: Home::class);', true],
    'macro second URI argument' => ["Route::landing('home', '/');", true],
    'macro under prefix remains opaque' => ["Route::prefix('admin')->homepage();", true],
    'macro non-root-looking argument' => ["Route::landing('/dashboard');", true],
    'macro registration is deferred' => ["Route::macro('homepage', fn () => Route::view('/', 'home'));", false],
    'macro query' => ["Route::hasMacro('/');", false],
    'route name query' => ["Route::has('/');", false],
    'permission attribute' => ["Route::can('view')->get('/health', fn () => 'ok');", false],
    'missing attribute' => ["Route::missing(fn () => require __DIR__.'/home.php')->get('/health', fn () => 'ok');", false],
    'as attribute' => ["Route::as('/')->get('/health', fn () => 'ok');", false],
    'metadata attribute' => ["Route::metadata(['key' => '/'])->get('/health', fn () => 'ok');", false],
    'include in route arguments' => ["Route::get('/health', require __DIR__.'/home.php');", true],
    'include in prefixed route arguments' => ["Route::prefix('admin')->get('/health', require __DIR__.'/home.php');", true],
    'include in registrar arguments' => ["Route::middleware(require __DIR__.'/home.php')->get('/health', fn () => 'ok');", true],
    'auth include in route arguments' => ["Route::get('/health', require __DIR__.'/auth.php');", false],
    'deferred include in route action' => ["Route::get('/health', fn () => require __DIR__.'/home.php');", false],
    'route binding' => ["Route::bind('home', fn () => require __DIR__.'/home.php');", false],
    'router query with dynamic argument' => ['Route::getRoutes($options);', false],
    'local router macro' => ["\$router = app('router'); \$router->homepage();", true],
    'router replacement' => ['Route::setRoutes($routes);', true],
    'compiled router replacement' => ['Route::setCompiledRoutes($routes);', true],
    'fallback' => ["Route::fallback(fn () => 'home');", true],
    'include once opaque' => ["include_once __DIR__.'/home.php';", true],
    'include opaque in invoked arrow' => ["(fn () => include __DIR__.'/home.php')();", true],
    'include opaque deferred' => ["\$later = fn () => include __DIR__.'/home.php';", false],
    'include opaque in class method' => ["class Routes { public function load() { require __DIR__.'/home.php'; } }", false],
    'include opaque condition' => ["if (require __DIR__.'/home.php') {}", true],
    'auth include once' => ["include_once __DIR__.'/auth.php';", false],
    'auth require once' => ["require_once __DIR__.'/auth.php';", false],
    'auth nested parentheses' => ["require ((__DIR__.'/auth.php'));", false],
    'auth include in invoked arrow' => ["(fn () => require __DIR__.'/auth.php')();", false],
    'auth include condition' => ["if (require __DIR__.'/auth.php') {}", false],
    'auth include within array' => ["\$loaded = [require __DIR__.'/auth.php', true];", false],
    'auth followed by opaque include' => ["require __DIR__.'/auth.php'; include __DIR__.'/home.php';", true],
    'auth different basename' => ["require __DIR__.'/other-auth.php';", true],
    'auth concatenated suffix' => ["require __DIR__.'/auth.php'.\$suffix;", true],
    'auth separately parenthesised operands' => ["require (__DIR__) . ('/auth.php');", true],
    'auth file still opaque through group' => ["Route::group([], __DIR__.'/auth.php');", true],
    'macro name middleware attribute' => ["Route::name('/')->middleware('web')->get('/health', fn () => 'ok');", false],
    'inertia non-root registration' => ["Route::inertia('/dashboard', 'Dashboard');", false],
    'livewire non-root registration' => ["Route::livewire('/dashboard', Home::class);", false],
]);

it('preserves executable macro homepages after stock removal', function (string $registration, string $invocation): void {
    $macros = new ReflectionProperty(Router::class, 'macros');
    $originalMacros = $macros->getValue();
    $content = "<?php\nuse Illuminate\\Support\\Facades\\Route;\n" . $registration . "\n" . $invocation;

    try {
        $editor = new RootRouteEditor($content . "\nRoute::view('/', 'welcome');");
        $edited = $editor->removeStockWelcomeRoutes();
        Route::setRoutes(new RouteCollection);
        eval(substr($edited, 5));

        expect($edited)->toBe($content . "\n")
            ->and(new RootRouteEditor($edited)->hasRootRoute())->toBeTrue()
            ->and(Route::getRoutes()->match(Request::create('/'))->run())->toBe('custom');
    } finally {
        $macros->setValue(null, $originalMacros);
    }
})->with([
    'no arguments' => [
        "Route::macro('rootRouteEditorHome', fn () => Route::get('/', fn () => 'custom'));",
        'Route::rootRouteEditorHome();',
    ],
    'non-URI argument' => [
        "Route::macro('rootRouteEditorHome', fn (\$component) => Route::get('/', fn () => \$component));",
        "Route::rootRouteEditorHome('custom');",
    ],
    'second URI argument' => [
        "Route::macro('rootRouteEditorHome', fn (\$component, \$uri) => Route::get(\$uri, fn () => \$component));",
        "Route::rootRouteEditorHome('custom', '/');",
    ],
    'prefix replaced internally' => [
        "Route::macro('rootRouteEditorHome', fn () => Route::get('/dashboard', fn () => 'custom')->setUri('/'));",
        "Route::prefix('admin')->group(fn () => Route::rootRouteEditorHome());",
    ],
]);
