<?php

declare(strict_types=1);

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Observers\ErrorPageModelInvalidationObserver;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Bus;
use Lorisleiva\Actions\Decorators\JobDecorator;

/**
 * @return array{site: Site, siteDomain: SiteDomain, errorPage: Page}
 */
function makeErrorPageSite(): array
{
    $language = Language::factory()->english()->create();
    $siteDomain = SiteDomain::factory()
        ->state(['language_id' => $language->id])
        ->create();

    $site = $siteDomain->site;
    $errorPage = resolve(PageCreator::class)->createErrorPage($site, $site->getAllLanguages());

    return ['site' => $site, 'siteDomain' => $siteDomain, 'errorPage' => $errorPage];
}

it('dispatches for a site update', function (): void {
    ['site' => $site] = makeErrorPageSite();

    $expectedSiteId = $site->id;
    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($expectedSiteId);

    $site->name = 'Renamed site';
    $site->save();
});

it('deduplicates queued regeneration per request without suppressing the next request', function (): void {
    $application = app();
    $originalEnvironment = $application->environment();
    $runningInConsole = new ReflectionProperty(Application::class, 'isRunningInConsole');
    $originalRunningInConsole = $runningInConsole->getValue($application);

    try {
        $application->detectEnvironment(fn (): string => 'local');
        $runningInConsole->setValue($application, false);
        Bus::fake();

        $site = new Site;
        $site->forceFill(['id' => 47, 'name' => 'Before']);
        $site->syncOriginal();
        $site->name = 'After';
        $site->syncChanges();

        $observer = resolve(ErrorPageModelInvalidationObserver::class);

        $observer->updatedFromEvent('eloquent.updated', [$site]);
        $observer->updatedFromEvent('eloquent.updated', [$site]);

        Bus::assertDispatchedAfterResponse(JobDecorator::class, 1);

        $application->forgetScopedInstances();
        $observer->updatedFromEvent('eloquent.updated', [$site]);

        Bus::assertDispatchedAfterResponse(JobDecorator::class, 2);
    } finally {
        $application->detectEnvironment(fn (): string => $originalEnvironment);
        $runningInConsole->setValue($application, $originalRunningInConsole);
    }
});

it('dispatches for a site domain update', function (): void {
    ['site' => $site, 'siteDomain' => $siteDomain] = makeErrorPageSite();

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $siteDomain->domain = 'changed.test';
    $siteDomain->save();
});

it('dispatches for an error page update', function (): void {
    ['site' => $site, 'errorPage' => $errorPage] = makeErrorPageSite();

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $errorPage->name = 'Updated error page';
    $errorPage->save();
});

it('dispatches for an error page update through a third-party page subclass', function (): void {
    ['site' => $site, 'errorPage' => $errorPage] = makeErrorPageSite();
    $thirdPartyPage = ThirdPartyErrorPage::query()->findOrFail($errorPage->id);
    ParentPageUpdateObserver::$handled = false;
    Page::observe(ParentPageUpdateObserver::class);

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $thirdPartyPage->name = 'Updated extension error page';
    $thirdPartyPage->save();

    expect(ParentPageUpdateObserver::$handled)->toBeFalse();
});

it('dispatches for a site-level translation update', function (): void {
    ['site' => $site] = makeErrorPageSite();

    $translation = $site->translations()->first();
    throw_unless($translation instanceof Translation, RuntimeException::class, 'Expected a site translation.');

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $translation->title = 'New site title';
    $translation->save();
});

it('dispatches for an error page translation update', function (): void {
    ['site' => $site, 'errorPage' => $errorPage] = makeErrorPageSite();

    $translation = $errorPage->translations()->first();
    throw_unless($translation instanceof Translation, RuntimeException::class, 'Expected an error page translation.');

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $translation->title = 'New error title';
    $translation->save();
});

it('dispatches for a site logo media update', function (): void {
    ['site' => $site] = makeErrorPageSite();
    $media = Media::factory()
        ->model($site)
        ->collection(MediaCollectionEnum::LogoInverted)
        ->create();

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $media->name = 'Updated logo';
    $media->save();
});

it('dispatches for a deleted site logo media record', function (): void {
    ['site' => $site] = makeErrorPageSite();
    $media = Media::factory()
        ->model($site)
        ->collection(MediaCollectionEnum::Logo)
        ->create();

    RegenerateSiteErrorPagesAction::shouldRun()
        ->once()
        ->with($site->id);

    $media->delete();
});

it('does not dispatch for an unrelated page and its translation', function (): void {
    ['site' => $site] = makeErrorPageSite();

    $page = Page::factory()->state(['site_id' => $site->id])->create();
    $translation = $page->translations()->create([
        'language_id' => $site->language?->id,
        'title' => 'Regular page',
        'content' => 'Body',
    ]);

    RegenerateSiteErrorPagesAction::shouldNotRun();

    $page->name = 'Updated regular page';
    $page->save();

    $translation->title = 'Updated regular title';
    $translation->save();
});

it('does not dispatch for non-logo site media', function (): void {
    ['site' => $site] = makeErrorPageSite();
    $media = Media::factory()
        ->model($site)
        ->collection(MediaCollectionEnum::Image)
        ->create();

    RegenerateSiteErrorPagesAction::shouldNotRun();

    $media->name = 'Updated image';
    $media->save();
});

it('does not dispatch for a timestamp-only update', function (): void {
    ['site' => $site] = makeErrorPageSite();

    RegenerateSiteErrorPagesAction::shouldNotRun();

    $site->touch();
});

final class ThirdPartyErrorPage extends Page
{
    protected $table = 'pages';

    #[Override]
    public function getMorphClass(): string
    {
        return (new Page)->getMorphClass();
    }
}

final class ParentPageUpdateObserver
{
    public static bool $handled = false;

    public function updated(): void
    {
        self::$handled = true;
    }
}
