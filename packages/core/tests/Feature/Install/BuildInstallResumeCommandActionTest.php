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

it('replays the preset and publish choices so a stopped preset install resumes the same way', function (): void {
    $command = BuildInstallResumeCommandAction::run(resumeInput(), [
        'artisan', 'capell:install', '--preset=marketing', '--publish', '--site-name=My Site', '--no-interaction',
    ]);

    expect($command)->toBe("php artisan capell:install '--preset=marketing' '--publish' '--site-name=My Site' '--no-interaction'");
});

it('replays only allowlisted options and never a secret of any kind', function (): void {
    $command = BuildInstallResumeCommandAction::run(resumeInput(), [
        'artisan', 'capell:install', '--fresh=force', '--plan', '--password', 'hunter2', '--role-user-password=hunter3', '--email=a@example.com',
        '--license-key=LIC-123', '--api-token', 'tok-456', '--token=tok-789', '--db-password=dbpass', '--database=prod', '--marketplace-key=mk-1',
        '--url=https://user:secret@example.com', '--handoff-json=/tmp/h.json', 'positional-secret', '--theme', 'bold',
    ]);

    expect($command)->toBe("php artisan capell:install '--email=a@example.com' '--theme=bold'")
        ->and($command)->not->toContain('hunter')
        ->and($command)->not->toContain('LIC-')
        ->and($command)->not->toContain('tok-')
        ->and($command)->not->toContain('secret')
        ->and($command)->not->toContain('dbpass');
});

it('drops a url that carries credentials or a query string', function (): void {
    expect(BuildInstallResumeCommandAction::run(resumeInput(), ['artisan', 'capell:install', '--url=https://example.com/?key=abc']))
        ->toBe('php artisan capell:install');
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
