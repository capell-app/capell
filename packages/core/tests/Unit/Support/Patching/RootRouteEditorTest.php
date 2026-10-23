<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\RootRouteEditor;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
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
