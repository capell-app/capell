<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallResumeCommandAction;
use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Data\Install\InstallStoppedStepData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Install\NullProgressReporter;

function resumeInput(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: ['capell-app/admin'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

it('replays the original install options so every earlier choice carries over', function (): void {
    $command = BuildInstallResumeCommandAction::run(resumeInput(), [
        'artisan', 'capell:install', '--theme=bold', '--site-name=My Site', '--no-seed-default-data', '--table-prefix=cms_',
    ]);

    expect($command)->toBe("php artisan capell:install '--theme=bold' '--site-name=My Site' '--no-seed-default-data' '--table-prefix=cms_'");
});

it('never replays options that destroy data, only preview or carry a secret', function (): void {
    $command = BuildInstallResumeCommandAction::run(resumeInput(), [
        'artisan', 'capell:install', '--fresh=force', '--plan', '--password', 'hunter2', '--role-user-password=hunter3', '--email=a@example.com', '--password=hunter4',
    ]);

    expect($command)->toBe("php artisan capell:install '--email=a@example.com'");
});

it('resumes an interactive install with the bare command', function (): void {
    expect(BuildInstallResumeCommandAction::run(resumeInput(), ['artisan', 'capell:install']))->toBe('php artisan capell:install');
});

it('falls back to the recorded packages and address when the run did not start from the console', function (): void {
    $command = BuildInstallResumeCommandAction::run(resumeInput(), ['vendor/bin/pest']);

    expect($command)->toBe("php artisan capell:install --no-interaction --url='https://example.com' --packages='capell-app/admin'");
});

it("forgets the previous run's stopped step when a new install run starts", function (): void {
    InstallRunState::recordStoppedStep(new InstallStoppedStepData('run-migrations-mid', 'Run migrations', 'php artisan capell:install'));
    $seenAtFirstStep = 'unset';

    app()->instance(InstallStepExecutor::class, new class($seenAtFirstStep)
    {
        public function __construct(private string &$seen) {}

        public function execute(string $stepKey, InstallRunState $state): InstallRunState
        {
            $this->seen = (string) InstallRunState::stoppedStep()?->key;

            throw new RuntimeException('stop after the first step');
        }
    });

    expect(fn (): mixed => RunInstallAction::run(resumeInput(), new NullProgressReporter))->toThrow(RuntimeException::class);
    expect($seenAtFirstStep)->toBe('');

    InstallRunState::clearStoppedStep();
});
