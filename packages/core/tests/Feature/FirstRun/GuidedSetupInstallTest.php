<?php

declare(strict_types=1);

use Capell\Admin\Actions\CreateDefaultPagesAction;
use Capell\Core\Actions\CreateDefaultLanguagesAction;
use Capell\Core\Actions\FirstRun\ResolveGuidedSetupStateAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Events\CapellInstallationCompleted;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Core\Support\FirstRun\SitePublicationMilestones;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Upgrade\RecordPublishedSitesUpgradeStep;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    CreateDefaultLanguagesAction::run(['en']);
    Queue::fake();
});

/** The site the admin setup step makes during a first install: a site and its default pages. */
function installStepSite(): Site
{
    // The install remembers that it started on an empty database and checks it after every step.
    InstallRunState::rememberInitialInstallation(true);
    $english = Language::query()->where('code', 'en')->firstOrFail();
    $theme = Theme::factory()->createOne(['key' => 'default', 'default' => true]);
    $site = Site::factory()->language($english)->withTranslations($english)->createOne(['theme_id' => $theme->getKey(), 'name' => 'My Laravel App']);
    CreateDefaultPagesAction::run($site->refresh(), $site->languages);
    InstallRunState::checkpointInitialInstallation();

    return $site->refresh();
}

/** Stands in for a package that seeds a page when the install completes, as Site Discovery seeds its sitemap page. */
function seedPageOnInstallCompleted(string $slug): void
{
    Event::listen(CapellInstallationCompleted::class, function () use ($slug): void {
        foreach (Site::query()->get() as $site) {
            resolve(PageCreator::class)->createPage(['name' => ucfirst($slug), 'translation' => ['slug' => $slug]], $site, $site->languages);
        }
    });
}

function completeInstall(bool $initialInstallation, ?string $suiteKey = null, bool $demoContent = false): void
{
    $reporter = new class implements ProgressReporter
    {
        public function step(string $label): void {}

        public function report(string $line): void {}

        public function error(string $line): void {}
    };

    resolve(InstallStepExecutor::class)->execute(InstallPlan::STEP_MARK_CORE_INSTALLED, new InstallRunState(
        new InstallInputData(
            siteUrl: 'https://example.com',
            packages: [],
            languages: ['en'],
            demoContent: $demoContent,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            initialInstallation: $initialInstallation,
            suiteKey: $suiteKey,
        ),
        $reporter,
    ));
}

function installAdmin(): User
{
    return test()->createUserWithRole('super_admin');
}

it('offers guided setup to the site a first install made, beside the pages packages seeded as it finished', function (): void {
    $site = installStepSite();
    seedPageOnInstallCompleted('sitemap');

    completeInstall(initialInstallation: true);

    $site->refresh();
    $state = ResolveGuidedSetupStateAction::run(installAdmin());

    expect(Page::query()->where('site_id', $site->getKey())->count())->toBe(5)
        ->and(ResolveGuidedSetupStateAction::isFreshInstall($site))->toBeTrue()
        ->and($state->needsSetup)->toBeTrue()
        ->and($state->siteId)->toBe((int) $site->getKey());
});

it("offers the blog suite's page set to a first install of that suite, beside the blog pages it seeded", function (): void {
    $site = installStepSite();
    seedPageOnInstallCompleted('blog');

    completeInstall(initialInstallation: true, suiteKey: 'blog');

    $state = ResolveGuidedSetupStateAction::run(installAdmin());

    expect($state->needsSetup)->toBeTrue()
        ->and($state->suiteKey)->toBe('blog')
        ->and(data_get($site->refresh()->meta, 'install_suite'))->toBe('blog');
});

it('never offers guided setup after an install that was not a first install', function (): void {
    $site = installStepSite();
    InstallRunState::completeInitialInstallation();

    completeInstall(initialInstallation: false);

    expect(ResolveGuidedSetupStateAction::isFreshInstall($site->refresh()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(installAdmin())->needsSetup)->toBeFalse();
});

it('keeps guided setup for a fresh install when an upgrade runs before anyone sets it up', function (): void {
    $site = installStepSite();
    completeInstall(initialInstallation: true);

    $step = resolve(RecordPublishedSitesUpgradeStep::class);
    $step->run(new UpgradeContext(composerVersions: [], ledgerVersions: [], appliedStepIds: []));

    expect(resolve(SitePublicationMilestones::class)->hasPublished((int) $site->getKey()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(installAdmin())->needsSetup)->toBeTrue();
});

it('never offers guided setup after a first install with sample content', function (): void {
    $site = installStepSite();

    completeInstall(initialInstallation: true, demoContent: true);

    expect(ResolveGuidedSetupStateAction::isFreshInstall($site->refresh()))->toBeFalse()
        ->and(ResolveGuidedSetupStateAction::run(installAdmin())->needsSetup)->toBeFalse();
});
