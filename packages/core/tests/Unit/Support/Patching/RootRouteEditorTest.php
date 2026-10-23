<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\RootRouteEditor;
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
    'middleware group' => ["Route::middleware('auth')->group(function () { Route::view('/', 'welcome'); });", false],
    'comment' => ["// Route::get('/', fn () => view('welcome'));", false],
    'string' => [' $example = "Route::view(\'/\', \'welcome\');";', false],
    'conditional block' => ["if (true) { Route::view('/', 'welcome'); }", false],
    'alternative block' => ["if (true): echo 'preview'; Route::view('/', 'welcome'); endif;", false],
    'nested alternative blocks' => ["switch (true): case true: if (true): echo 'preview'; endif; Route::view('/', 'welcome'); endswitch;", false],
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
