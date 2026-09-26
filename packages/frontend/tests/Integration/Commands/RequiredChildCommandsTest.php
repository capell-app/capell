<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Frontend\Actions\GenerateTailwindAssetsAction;
use Capell\Frontend\Actions\IntegrateViteInputsAction;
use Capell\Frontend\Actions\WriteViteInputManifestAction;
use Capell\Frontend\Console\Commands\InstallCommand;
use Capell\Frontend\Console\Commands\UpgradeCommand;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('fails frontend lifecycle commands when required asset publication fails', function (string $commandClass, string $failedTag): void {
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    $generatedAssets = bindFakeAction(GenerateTailwindAssetsAction::class, []);
    bindFakeAction(WriteViteInputManifestAction::class);
    bindFakeAction(IntegrateViteInputsAction::class);

    $command = match ($commandClass) {
        InstallCommand::class => Mockery::mock(InstallCommand::class . '[call,callSilent]', [$filesystem]),
        UpgradeCommand::class => Mockery::mock(UpgradeCommand::class . '[call,callSilent]', []),
        default => throw new InvalidArgumentException('Unsupported lifecycle command fixture.'),
    };
    $tags = [];
    $command->shouldReceive('call')->andReturnUsing(function (string $child, array $arguments = []) use (&$tags, $failedTag): int {
        if ($child === 'vendor:publish') {
            $tags[] = $arguments['--tag'];

            return $arguments['--tag'] === $failedTag ? 7 : 0;
        }

        return 0;
    });
    $command->setLaravel(app());
    $output = new BufferedOutput;

    expect($command->run(new ArrayInput([]), $output))->toBe(Command::FAILURE)
        ->and($output->fetch())->toContain('vendor:publish', '7')
        ->and(end($tags))->toBe($failedTag);

    expect($generatedAssets->called)->toBeFalse();
})->with([
    'install assets' => [InstallCommand::class, 'capell-frontend-assets'],
    'install publish' => [InstallCommand::class, 'capell-frontend-publish'],
    'upgrade assets' => [UpgradeCommand::class, 'capell-frontend-assets'],
    'upgrade publish' => [UpgradeCommand::class, 'capell-frontend-publish'],
]);
