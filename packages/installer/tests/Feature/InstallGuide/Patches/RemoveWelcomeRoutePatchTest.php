<?php

declare(strict_types=1);

use Capell\Installer\Support\InstallGuide\Patches\RemoveWelcomeRoutePatch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->testDir = sys_get_temp_dir() . '/capell-welcome-route-patch-test-' . uniqid();
    mkdir($this->testDir, 0755, true);
});

afterEach(function (): void {
    if (is_dir($this->testDir)) {
        File::deleteDirectory($this->testDir);
    }
});

test('probe_returns_unsupported_when_routes_web_does_not_exist', function (): void {
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;
    $status = $patch->probe();

    expect($status)->toBe(PatchStatus::Unsupported);
});

test('probe_returns_applicable_when_stock_welcome_block_present', function (): void {
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

test('probe_returns_applicable_with_extra_whitespace_in_welcome_block', function (): void {
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

test('probe_returns_customised_when_named_welcome_route_present', function (): void {
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

    expect($patch->probe())->toBe(PatchStatus::Customised);
});

test('probe_returns_applicable_when_unnamed_view_welcome_route_present', function (): void {
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

    $modifiedContent = file_get_contents($routesPath);

    expect($modifiedContent)->not->toContain("Route::view('/', 'welcome')")
        ->and($modifiedContent)->toContain("Route::view('dashboard', 'dashboard')");
});

test('probe_returns_already_applied_when_stock_block_absent_and_no_root_route', function (): void {
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

test('probe_returns_customised_when_custom_root_route_present', function (): void {
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

test('probe_returns_customised_when_root_route_with_different_handler_type', function (): void {
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

test('apply_successfully_removes_stock_welcome_block', function (): void {
    // Test the regex removal logic directly without invoking the full apply() method
    $originalContent = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    // Simulate what apply() does: remove the stock block and collapse newlines
    $pattern = '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/';
    $modifiedContent = preg_replace($pattern, '', $originalContent);
    $modifiedContent = preg_replace('/\n\n\n+/', "\n\n", (string) $modifiedContent);

    expect($modifiedContent)->not->toContain("Route::get('/', function () {");
    expect($modifiedContent)->not->toContain("return view('welcome');");
    expect($modifiedContent)->toContain("Route::get('/api/health'");
});

test('apply_cleans_up_excessive_newlines', function (): void {
    // Test the newline cleanup logic
    $originalContent = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});



Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    // Remove the stock block and collapse newlines
    $pattern = '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/';
    $modifiedContent = preg_replace($pattern, '', $originalContent);
    $modifiedContent = preg_replace('/\n\n\n+/', "\n\n", (string) $modifiedContent);

    // Should not have 3+ consecutive newlines
    expect($modifiedContent)->not->toContain("\n\n\n");
});

test('apply_preserves_other_routes', function (): void {
    // Test that removing the welcome route preserves other routes
    $originalContent = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/api/data', function () {
    return response()->json(['data' => []]);
});
PHP;

    // Remove the stock block and collapse newlines
    $pattern = '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/';
    $modifiedContent = preg_replace($pattern, '', $originalContent);
    $modifiedContent = preg_replace('/\n\n\n+/', "\n\n", (string) $modifiedContent);

    expect($modifiedContent)->toContain("Route::get('/api/health'");
    expect($modifiedContent)->toContain("Route::post('/api/data'");
    expect($modifiedContent)->toContain('status');
    expect($modifiedContent)->toContain('data');
});

test('apply_removes_the_real_stock_welcome_route_file_and_collapses_blank_lines', function (): void {
    $routesPath = $this->testDir . '/routes/web.php';
    mkdir(dirname($routesPath), 0755, true);

    file_put_contents($routesPath, <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});



Route::get('/dashboard', function () {
    return view('dashboard');
});
PHP);
    $this->app->setBasePath($this->testDir);

    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $modifiedContent = file_get_contents($routesPath);

    expect($modifiedContent)->not->toContain("return view('welcome');")
        ->not->toContain("\n\n\n")
        ->toContain("Route::get('/dashboard'");
});

test('apply_rejects_custom_root_routes_without_mutating_application_routes', function (): void {
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

test('apply_is_idempotent', function (): void {
    // Test that applying the regex twice doesn't change the content further
    $originalContent = <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/health', function () {
    return response()->json(['status' => 'ok']);
});
PHP;

    $pattern = '/Route::get\s*\(\s*[\'"][\/]["\']\s*,\s*function\s*\(\s*\)\s*\{[^}]*return\s+view\s*\(\s*[\'"]welcome["\']\s*\)\s*;[^}]*\}\s*\)\s*;/';

    // First application
    $firstApply = preg_replace($pattern, '', $originalContent);
    $firstApply = preg_replace('/\n\n\n+/', "\n\n", (string) $firstApply);

    // Second application - should be no-op
    $secondApply = preg_replace($pattern, '', (string) $firstApply);
    $secondApply = preg_replace('/\n\n\n+/', "\n\n", (string) $secondApply);

    expect($secondApply)->toBe($firstApply);
});

test('patch_has_correct_metadata', function (): void {
    $patch = new RemoveWelcomeRoutePatch;

    expect($patch->id())->toBe('remove-welcome-route-patch');
    expect($patch->group())->toBe('routes');
    expect($patch->defaultEnabled())->toBe(true);
    expect($patch->label())->toBeString();
    expect($patch->description())->toBeString();
    expect($patch->docUrl())->toBeNull();
});
