<?php

declare(strict_types=1);

use Capell\Core\Tests\Support\NestedTestProcess;
use Capell\Tests\PackagesTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->oldBase = app()->basePath();
    $this->oldApp = app()->path();
    $this->oldConfig = config('capell');
    $this->workspace = getcwd() . '/var/generatedmaker' . bin2hex(random_bytes(8));
    app()->setBasePath($this->workspace);
    app()->useAppPath($this->workspace . '/app');
    config(['capell' => require getcwd() . '/packages/core/config/capell.php']);
});

afterEach(function (): void {
    app()->setBasePath($this->oldBase);
    app()->useAppPath($this->oldApp);
    config(['capell' => $this->oldConfig]);
    new Filesystem()->deleteDirectory($this->workspace);
});

it('creates a runnable test for an autoloaded maker', function (string $maker, string $classPath, string $testPath): void {
    expect(Artisan::call('capell:make', ['maker' => $maker, '--name' => 'Example']))->toBe(0);
    expect(Artisan::output())->toContain('No registration is required');
    expect(base_path($classPath))->toBeFile()->and(base_path($testPath))->toBeFile();
    $lint = NestedTestProcess::make([PHP_BINARY, '-l', base_path($testPath)]);
    $lint->run();

    expect($lint->isSuccessful())->toBeTrue($lint->getOutput() . $lint->getErrorOutput());
    $pest = NestedTestProcess::make([PHP_BINARY, getcwd() . '/vendor/bin/pest', '--configuration=' . getcwd() . '/phpunit.xml', base_path($testPath)], getcwd());
    $pest->run();

    expect($pest->isSuccessful())->toBeTrue($pest->getOutput() . $pest->getErrorOutput());
})->with([
    ['core.action', 'app/Actions/ExampleAction.php', 'tests/Feature/Generated/ExampleActionTest.php'],
    ['core.data', 'app/Data/ExampleData.php', 'tests/Feature/Generated/ExampleDataTest.php'],
    ['core.schema', 'app/Schemas/ExampleSchema.php', 'tests/Feature/Generated/ExampleSchemaTest.php'],
]);

it('creates a runnable test for a generated page schema extender and tells the developer to tag it', function (): void {
    expect(Artisan::call('capell:make', ['maker' => 'core.extender', '--name' => 'Example']))->toBe(0);
    expect(Artisan::output())->toContain('PageSchemaExtender::TAG');
    expect(base_path('app/Extenders/ExampleExtender.php'))->toBeFile()->and(base_path('tests/Feature/Generated/ExampleExtenderTest.php'))->toBeFile();
    $pest = NestedTestProcess::make([PHP_BINARY, getcwd() . '/vendor/bin/pest', '--configuration=' . getcwd() . '/phpunit.xml', base_path('tests/Feature/Generated/ExampleExtenderTest.php')], getcwd());
    $pest->run();

    expect($pest->isSuccessful())->toBeTrue($pest->getOutput() . $pest->getErrorOutput());
});

it('creates a blueprint subject model with a runnable registry test and tells the developer how to register it', function (): void {
    $code = Artisan::call('capell:make', ['maker' => 'core.blueprint', '--name' => 'Example']);
    $output = Artisan::output();
    expect($code)->toBe(0, $output)->and($output)->toContain('blueprintSubject(');
    expect(base_path('app/Blueprints/Example.php'))->toBeFile()->and(base_path('tests/Feature/Generated/ExampleBlueprintSubjectTest.php'))->toBeFile();
    $pest = NestedTestProcess::make([PHP_BINARY, getcwd() . '/vendor/bin/pest', '--configuration=' . getcwd() . '/phpunit.xml', base_path('tests/Feature/Generated/ExampleBlueprintSubjectTest.php')], getcwd());
    $pest->run();

    expect($pest->isSuccessful())->toBeTrue($pest->getOutput() . $pest->getErrorOutput());
});

it('creates a runnable test for each generated component', function (string $maker, string $viewPath, string $testPath, array $extraFiles): void {
    $code = Artisan::call('capell:make', ['maker' => $maker, '--name' => 'Example']);
    $output = Artisan::output();
    expect($code)->toBe(0, $output)->and(base_path($viewPath))->toBeFile()->and(base_path($testPath))->toBeFile();

    foreach ($extraFiles as $extra) {
        expect(base_path($extra))->toBeFile();
    }

    // A real application binds tests/Feature to its TestCase in tests/Pest.php; mirror that binding for the workspace copy.
    $generated = (string) file_get_contents(base_path($testPath));
    file_put_contents(base_path($testPath), str_replace('declare(strict_types=1);', 'declare(strict_types=1);

uses(' . PackagesTestCase::class . '::class);', $generated));
    $pest = NestedTestProcess::make([PHP_BINARY, getcwd() . '/vendor/bin/pest', '--configuration=' . getcwd() . '/phpunit.xml', base_path($testPath)], getcwd());
    $pest->run();

    expect($pest->isSuccessful())->toBeTrue($pest->getOutput() . $pest->getErrorOutput());
})->with([
    'page blade' => ['core.page-blade-component', 'resources/views/components/page/example.blade.php', 'tests/Feature/Generated/ExamplePageComponentTest.php', []],
    'asset blade' => ['core.asset-blade-component', 'resources/views/components/asset/example.blade.php', 'tests/Feature/Generated/ExampleAssetComponentTest.php', []],
    'page livewire' => ['core.page-livewire-component', 'app/Livewire/Example.php', 'tests/Feature/Generated/ExampleLivewireTest.php', ['resources/views/livewire/example.blade.php']],
]);

it('refuses names that would break generated paths or PHP strings', function (string $maker, string $name): void {
    $code = Artisan::call('capell:make', ['maker' => $maker, '--name' => $name]);

    expect($code)->not->toBe(0)
        ->and(Artisan::output())->toContain('The name must')
        ->and(base_path('app'))->not->toBeDirectory()
        ->and(base_path('tests'))->not->toBeDirectory();
})->with([
    'slash in a class name' => ['core.action', 'Blog/Publish'],
    'dot in a class name' => ['core.data', 'Blog.Publish'],
    'quote in a component name' => ['core.page-blade-component', "it's"],
    'slash in an asset component name' => ['core.asset-blade-component', 'hero/image'],
]);

it('still writes generated tests when a published config lacks the generated-tests root', function (): void {
    config(['capell.diagnostics.allowed_roots' => [app_path('Actions'), app_path('Data')]]);

    $code = Artisan::call('capell:make', ['maker' => 'core.action', '--name' => 'Example']);

    expect($code)->toBe(0, Artisan::output())
        ->and(base_path('tests/Feature/Generated/ExampleActionTest.php'))->toBeFile();
});

it('writes a test for the data class generated beside an action', function (): void {
    $code = Artisan::call('capell:make:action', ['name' => 'Example', '--data' => true]);

    expect($code)->toBe(0, Artisan::output())
        ->and(base_path('app/Data/ExampleData.php'))->toBeFile()
        ->and(base_path('tests/Feature/Generated/ExampleDataTest.php'))->toBeFile();
});
