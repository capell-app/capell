<?php

declare(strict_types=1);

use Capell\Admin\Actions\SyncCapellPermissionsAction;
use Capell\Admin\Actions\SyncDashboardFilamentWidgetSettingsAction;
use Capell\Admin\Console\Commands\InstallCommand;
use Capell\Admin\Console\Commands\SetupCommand;
use Capell\Admin\Console\Commands\UpgradeCommand;
use Capell\Core\Actions\Upgrade\RunDatabaseMigrationsAction;
use Capell\Core\Data\MigrationRunResult;
use Capell\Core\Support\Migration\MigrationFilesystemInterface;
use Capell\Core\Tests\Support\Stubs\FakeMigrationFilesystem;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

it('stops admin lifecycle commands at the first failed required stage', function (string $commandClass, array $expectedCalls, string $failedStage): void {
    $filesystem = new FakeMigrationFilesystem;
    app()->instance(MigrationFilesystemInterface::class, $filesystem);
    SyncCapellPermissionsAction::shouldRun()->andReturnNull();
    bindFakeAction(SyncDashboardFilamentWidgetSettingsAction::class);
    RunDatabaseMigrationsAction::shouldRun()->andReturnUsing(
        fn (): MigrationRunResult => new MigrationRunResult($failedStage === 'database migrations' ? 7 : 0, 'Migration diagnostic'),
    );

    $command = match ($commandClass) {
        InstallCommand::class => Mockery::mock(InstallCommand::class . '[call,callSilent]', [$filesystem]),
        SetupCommand::class => Mockery::mock(SetupCommand::class . '[call,callSilent]', []),
        UpgradeCommand::class => Mockery::mock(UpgradeCommand::class . '[call,callSilent]', []),
        default => throw new InvalidArgumentException('Unsupported lifecycle command fixture.'),
    };
    $calls = [];
    $command->shouldReceive('call', 'callSilent')->andReturnUsing(function (string $child, array $arguments = []) use (&$calls, $failedStage): int {
        $calls[] = $child;

        return $child === $failedStage ? 7 : 0;
    });
    $command->setLaravel(app());
    $options = [];

    if ($commandClass === SetupCommand::class) {
        $user = User::factory()->createOne();
        $options = [
            '--url' => 'https://example.test',
            '--user' => $user->email,
            '--languages' => 'en',
            '--sites' => 'Required stages',
            '--assets' => ['resources/css/app.css'],
            '--skip-panel-integration' => true,
            '--force' => true,
        ];
    }

    $output = new BufferedOutput;
    $exitCode = $command->run(new ArrayInput($options), $output);
    $text = $output->fetch();

    expect($exitCode)->toBe(Command::FAILURE)
        ->and($text)->toContain($failedStage, '7')
        ->not->toContain('installed successfully', 'upgraded successfully', 'Admin setup complete.');

    expect($calls)->toBe($expectedCalls);
})->with(function (): iterable {
    $commands = [
        InstallCommand::class => ['capell:publish-migrations', 'migrate', 'filament:clear-cached-components', 'filament:cache-components', 'filament:assets'],
        UpgradeCommand::class => ['vendor:publish', 'database migrations', 'shield:generate', 'filament:clear-cached-components', 'filament:cache-components', 'filament:assets'],
        SetupCommand::class => ['capell:publish-migrations', 'migrate', 'shield:super-admin', 'shield:generate'],
    ];

    foreach ($commands as $commandClass => $stages) {
        $expectedCalls = [];

        foreach ($stages as $failedStage) {
            if ($failedStage !== 'database migrations') {
                $expectedCalls[] = $failedStage;
            }

            yield $commandClass . ' / ' . $failedStage => [$commandClass, $expectedCalls, $failedStage];
        }
    }
});
