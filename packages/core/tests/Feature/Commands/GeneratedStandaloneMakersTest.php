<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->oldBase = app()->basePath();
    $this->oldApp = app()->path();
    $this->oldRoots = config('capell.diagnostics.allowed_roots');
    $this->workspace = getcwd() . '/var/generatedmaker' . bin2hex(random_bytes(8));
    app()->setBasePath($this->workspace);
    app()->useAppPath($this->workspace . '/app');
    config(['capell.diagnostics.allowed_roots' => [$this->workspace]]);
});

afterEach(function (): void {
    app()->setBasePath($this->oldBase);
    app()->useAppPath($this->oldApp);
    config(['capell.diagnostics.allowed_roots' => $this->oldRoots]);
    new Filesystem()->deleteDirectory($this->workspace);
});

it('creates a runnable test for an autoloaded maker', function (string $maker, string $classPath, string $testPath): void {
    expect(Artisan::call('capell:make', ['maker' => $maker, '--name' => 'Example']))->toBe(0);
    expect(Artisan::output())->toContain('No registration is required');
    expect(base_path($classPath))->toBeFile()->and(base_path($testPath))->toBeFile();
    $lint = new Process([PHP_BINARY, '-l', base_path($testPath)]);
    $lint->run();

    expect($lint->isSuccessful())->toBeTrue($lint->getOutput() . $lint->getErrorOutput());
    $pest = new Process([PHP_BINARY, getcwd() . '/vendor/bin/pest', '--configuration=' . getcwd() . '/phpunit.xml', base_path($testPath)], getcwd());
    $pest->run();

    expect($pest->isSuccessful())->toBeTrue($pest->getOutput() . $pest->getErrorOutput());
})->with([
    ['core.action', 'app/Actions/ExampleAction.php', 'tests/Feature/Generated/ExampleActionTest.php'],
    ['core.data', 'app/Data/ExampleData.php', 'tests/Feature/Generated/ExampleDataTest.php'],
]);
