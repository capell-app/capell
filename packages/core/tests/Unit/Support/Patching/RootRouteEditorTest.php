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
