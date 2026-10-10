<?php

declare(strict_types=1);

use Capell\Core\Actions\GetPluginsAction;
use Capell\Core\Actions\Install\PreflightExtraPackagesAction;
use Capell\Core\Actions\Install\RequireExtraPackagesAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Composer\InstallPackageArguments;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Support\Fakes\FakeProcessFactory;

it('uses catalogue prerelease constraints consistently without lowering host stability', function (bool $dryRun): void {
    config(['app.env' => 'local']);
    GetPluginsAction::mock()->shouldReceive('handle')->with('download')->andReturn(collect([
        'capell-app/address' => new PackageData(name: 'capell-app/address', type: PackageTypeEnum::Plugin, version: '1.1.0-beta.45', tier: 'free'),
    ]));
    $factory = FakeProcessFactory::bind()->push(exitCode: 1, errorOutput: 'unavailable');

    $run = function () use ($dryRun): void {
        $packages = ['capell-app/address', 'vendor/explicit:^2.0'];
        if ($dryRun) {
            PreflightExtraPackagesAction::run($packages, new NullProgressReporter);
        } else {
            RequireExtraPackagesAction::run($packages, new NullProgressReporter);
        }
    };

    expect($run)
        ->toThrow(RuntimeException::class, 'unavailable');

    $command = $factory->commands()[0];
    expect($command)->toContain('capell-app/address:^1.1.0-beta.45', 'vendor/explicit:^2.0')
        ->and(in_array('--dry-run', $command, true))->toBe($dryRun)
        ->and($factory->processes[0]->getTimeout())->toBe(600.0);
})->with([
    'preflight' => true,
    'require' => false,
]);

it('retains a prerelease constraint when Composer has already downloaded a selected dependency', function (): void {
    CapellCore::registerPackage('capell-app/address');
    CapellCore::getPackage('capell-app/address')->version = '1.1.0-beta.45';
    GetPluginsAction::mock()->shouldReceive('handle')->with('download')->andReturn(collect());

    expect(resolve(InstallPackageArguments::class)->resolve(['capell-app/address']))
        ->toBe(['capell-app/address:^1.1.0-beta.45']);
});

it('leaves stable and explicit requirements to Composer', function (): void {
    GetPluginsAction::mock()->shouldReceive('handle')->with('download')->andReturn(collect([
        'capell-app/blog' => new PackageData(name: 'capell-app/blog', type: PackageTypeEnum::Plugin, version: '1.0.50'),
    ]));

    expect(resolve(InstallPackageArguments::class)->resolve(['capell-app/blog', 'vendor/package:^2.0']))
        ->toBe(['capell-app/blog', 'vendor/package:^2.0']);
});

it('includes the existing Core constraint only for a local path installation', function (string $distribution, array $packages, array $expected): void {
    $directory = sys_get_temp_dir() . '/capell-composer-owned-' . bin2hex(random_bytes(8));
    mkdir($directory);
    file_put_contents($directory . '/composer.json', json_encode(['require' => ['capell-app/core' => '^1.0.66']], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/composer.lock', json_encode(['packages' => [['name' => 'capell-app/core', 'dist' => ['type' => $distribution]]]], JSON_THROW_ON_ERROR));
    GetPluginsAction::mock()->shouldReceive('handle')->with('download')->andReturn(collect());
    try {
        expect(new InstallPackageArguments($directory)->resolve($packages))->toBe($expected);
    } finally {
        unlink($directory . '/composer.json');
        unlink($directory . '/composer.lock');
        rmdir($directory);
    }
})->with([
    'local Core' => ['path', ['vendor/extension'], ['vendor/extension', 'capell-app/core:^1.0.66']],
    'public Core' => ['zip', ['vendor/extension'], ['vendor/extension']],
    'explicit Core' => ['path', ['vendor/extension', 'capell-app/core:^1.0'], ['vendor/extension', 'capell-app/core:^1.0']],
    'no downloads' => ['path', [], []],
]);
