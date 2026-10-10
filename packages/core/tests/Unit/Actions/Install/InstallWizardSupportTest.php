<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\BuildInstallFrontendAssetsAction;
use Capell\Core\Actions\Install\ReportInstallFailureAction;
use Capell\Core\Actions\Install\SaveInstallProfileAction;
use Capell\Core\Actions\Install\UpdateInstallAppUrlAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallProfileRepository;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Capell\Tests\Support\Fakes\FakeConsoleKernel;
use Illuminate\Support\Facades\Artisan;

function wizardInput(): InstallInputData
{
    return new InstallInputData(siteUrl: 'https://operator:secret@example.test:8443/blog?token=private#fragment', packages: ['capell-app/frontend'], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false, newUser: new NewUserData(name: 'Operator', email: 'private@example.test', password: 'secret-password'), selectedThemeKey: 'default');
}

it('exports a reusable profile without credentials and keeps configured profiles available', function (): void {
    $path = base_path('capell-install-profiles.json');
    $before = is_file($path) ? file_get_contents($path) : null;
    config(['capell.install_profiles' => ['existing' => ['packages' => ['vendor/existing']]]]);
    try {
        SaveInstallProfileAction::run(wizardInput(), 'owned-wizard-test', buildAssets: true);
        $profile = resolve(InstallProfileRepository::class)->find('owned-wizard-test');
        expect($profile?->siteUrl)->toBe('https://example.test:8443/blog')
            ->and($profile?->packages)->toBe(['capell-app/frontend'])
            ->and($profile?->buildAssets)->toBeTrue()
            ->and($profile?->seedDatabase)->toBeFalse()
            ->and(resolve(InstallProfileRepository::class)->find('existing')?->packages)->toBe(['vendor/existing']);
        expect(file_get_contents($path))->not->toContain('secret', 'private@example.test', 'token=', 'password', 'userId');
        expect(fn (): mixed => SaveInstallProfileAction::run(wizardInput(), 'owned-wizard-test'))->toThrow(RuntimeException::class, 'already exists');
    } finally {
        if ($before === null) {
            unlink($path);
        } else {
            file_put_contents($path, $before);
        }
    }
});

it('delegates dependency installation and builds to the existing frontend package manager workflow', function (): void {
    $completed = false;
    Artisan::command('capell:frontend-after-install {--apply}', function () use (&$completed): int {
        $completed = (bool) $this->option('apply');

        return 0;
    });
    BuildInstallFrontendAssetsAction::run();
    expect($completed)->toBeTrue();
});

it('reports completed steps and offers asset recovery without repeating package or demo setup', function (bool $frontendAvailable, string $command): void {
    FakeConsoleKernel::bind($frontendAvailable ? ['capell:frontend-after-install' => new stdClass] : []);
    Artisan::clearResolvedInstances();
    $reporter = new InstallSupportActionReporter;
    ReportInstallFailureAction::run(wizardInput(), [InstallPlan::STEP_MARK_CORE_INSTALLED], InstallPlan::STEP_REBUILD_RESOURCES, $reporter);
    $messages = implode("\n", array_column($reporter->lines, 1));
    expect($messages)->toContain('Installation stopped at rebuild-resources.', '1 steps completed: mark-core-installed', $command, 'does not repeat demo');
})->with([[true, 'php artisan capell:frontend-after-install --apply --no-interaction'], [false, 'npm install && npm run build']]);

it('reports a read only next command without leaking URL credentials or suggesting a fresh reinstall', function (): void {
    $reporter = new InstallSupportActionReporter;
    ReportInstallFailureAction::run(wizardInput(), [], InstallPlan::STEP_RUN_MIGRATIONS_PRE, $reporter);
    $messages = implode("\n", array_column($reporter->lines, 1));
    expect($messages)->toContain('0 steps completed:', '--plan --no-interaction', 'do not repeat --fresh')
        ->not->toContain('secret', 'token=');
});

it('updates APP_URL with the site port and path and preserves other environment settings', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-env-owned-');
    expect($path)->not->toBeFalse();
    throw_if($path === false, RuntimeException::class, 'Unable to create the owned environment fixture.');

    file_put_contents($path, "APP_URL=http://localhost\nOTHER_SETTING=preserved\n");
    try {
        UpdateInstallAppUrlAction::run(wizardInput()->siteUrl, $path);
        expect(file_get_contents($path))->toContain('APP_URL=https://example.test:8443/blog', 'OTHER_SETTING=preserved')
            ->not->toContain('secret', 'token=')
            ->and(config('app.url'))->toBe('https://example.test:8443/blog');
    } finally {
        unlink($path);
    }
});

it('preserves the browser facing localhost port in install environment settings', function (string $url): void {
    $path = tempnam(sys_get_temp_dir(), 'capell-local-env-');
    throw_if($path === false, RuntimeException::class, 'Unable to create the owned environment fixture.');
    file_put_contents($path, "APP_URL=http://localhost\nOTHER_SETTING=preserved\n");

    try {
        UpdateInstallAppUrlAction::run($url, $path);
        expect(file_get_contents($path))->toContain('APP_URL=' . $url, 'OTHER_SETTING=preserved')
            ->and(config('app.url'))->toBe($url);
    } finally {
        unlink($path);
    }
})->with(['host development server' => 'http://localhost:8000', 'Docker published port' => 'http://localhost:8080']);
