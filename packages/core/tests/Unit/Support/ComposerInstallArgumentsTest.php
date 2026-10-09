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
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Symfony\Component\Process\Process;

it('uses catalogue prerelease constraints consistently without lowering host stability', function (bool $dryRun): void {
    config(['app.env' => 'local']);
    GetPluginsAction::mock()->shouldReceive('handle')->with('download')->andReturn(collect([
        'capell-app/address' => new PackageData(name: 'capell-app/address', type: PackageTypeEnum::Plugin, version: '1.1.0-beta.45', tier: 'free'),
    ]));
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->with(600)->once()->andReturnSelf();
    $process->shouldReceive('disableOutput')->zeroOrMoreTimes()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function (Closure $output): int {
        $output('err', 'unavailable');

        return 0;
    });
    // Failure avoids a real autoloader reload in the require test.
    $process->shouldReceive('isSuccessful')->once()->andReturn(false);
    $process->shouldReceive('getOutput', 'getErrorOutput')->zeroOrMoreTimes()->andReturn('unavailable');
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with(
        Mockery::on(fn (array $command): bool => in_array('capell-app/address:^1.1.0-beta.45', $command, true)
            && in_array('vendor/explicit:^2.0', $command, true)
            && in_array('--dry-run', $command, true) === $dryRun),
        base_path(),
        Mockery::type('array'),
    )->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

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
