<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\PageCreator;
use Capell\Frontend\Actions\GenerateMaintenancePageCacheAction;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Contracts\StaticErrorPageStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Events\FrontendRenderPreparing;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Frontend\Support\Themes\FrontendThemePreviewRenderer;
use Capell\Frontend\Tests\Support\RecordingStaticErrorPageStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpFoundation\Response;

it('preserves a system page identity and authored layout when regenerated', function (bool $maintenance, bool $customisedLayout): void {
    $site = Model::withoutEvents(fn (): Site => Site::factory()->withTranslations()->create());
    $creator = resolve(PageCreator::class);
    $original = $maintenance ? $creator->createMaintenancePage($site) : $creator->createErrorPage($site);
    $customLayout = $customisedLayout ? Layout::factory()->create() : $original->layout;
    Model::withoutEvents(fn (): bool => $original->forceFill([
        'layout_id' => $customLayout->id,
        'name' => 'Authored recovery',
        'meta' => ['authored' => 'preserve'],
    ])->save());
    $canonicalId = $original->pageUrls()->whereNull('type')->sole()->getKey();
    $translation = $original->translations()->firstOrFail();
    $translationAttributes = $translation->getAttributes();

    $regenerated = $maintenance ? $creator->createMaintenancePage($site) : $creator->createErrorPage($site);

    expect($regenerated->getKey())->toBe($original->getKey())
        ->and($regenerated->layout_id)->toBe($customLayout->id)
        ->and($regenerated->name)->toBe('Authored recovery')
        ->and($regenerated->meta)->toBe($original->meta)
        ->and($regenerated->pageUrls()->whereNull('type')->sole()->getKey())->toBe($canonicalId)
        ->and(expectPresent($translation->fresh())->getAttributes())->toBe($translationAttributes)
        ->and(Page::query()->where('site_id', $site->id)->where('blueprint_id', $original->blueprint_id)->count())->toBe(1);
})->with(['error' => false, 'maintenance' => true])->with(['system layout' => false, 'custom layout' => true]);

it('preserves every caller frontend field across background system-page generation', function (bool $maintenance): void {
    $store = new class extends RecordingStaticErrorPageStore implements StaticMaintenancePageStore {};
    app()->instance(StaticErrorPageStore::class, $store);
    app()->instance(StaticMaintenancePageStore::class, $store);

    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create(['language_id' => $language->id]);
    $callerPage = resolve(PageCreator::class)->createErrorPage($site);
    $state = resolve(FrontendState::class);
    $state->reset()->withSite($site)->withPage($callerPage)
        ->withLayout($callerPage->layout)->withTheme($site->theme)
        ->withDomain($site->siteDomains->firstOrFail())->withLanguage(new Language(['code' => 'ar']))
        ->withParams(['caller' => 'value'])->withSlug('caller-slug')
        ->withRelativePath('/caller-path')->setEffectiveUrl('https://caller.test/path')
        ->setRevisionPageId(123)->markAsError()->setFrontendData('caller', ['nested' => true]);
    $before = clone $state;

    $writesBefore = $store->writes;
    if ($maintenance) {
        GenerateMaintenancePageCacheAction::run($site);
    } else {
        RegenerateSiteErrorPagesAction::run((int) $site->getKey());
    }

    expect($store->writes)->toBeGreaterThan($writesBefore)
        ->and($state)->toEqual($before);
})->with(['error' => false, 'maintenance' => true]);

it('restores caller context when a preview throws after seeding its context', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($language)->create(['language_id' => $language->id]);
    $callerPage = resolve(PageCreator::class)->createErrorPage($site);
    $state = resolve(FrontendState::class);
    $state->reset()->withSite($site)->withPage($callerPage)
        ->withLayout($callerPage->layout)->withTheme($site->theme)
        ->withDomain($site->siteDomains->firstOrFail())->withLanguage(new Language(['code' => 'ar']))
        ->withParams(['caller' => 'value'])->withSlug('caller-slug')
        ->withRelativePath('/caller-path')->setEffectiveUrl('https://caller.test/path')
        ->setRevisionPageId(123)->markAsError()->setFrontendData('caller', ['nested' => true]);
    $before = clone $state;
    Event::listen(FrontendRenderPreparing::class, function (): never {
        throw new RuntimeException('Preview stopped.');
    });

    expect(fn (): Response => resolve(FrontendThemePreviewRenderer::class)->render($site->theme, $site, $callerPage, $language))
        ->toThrow(RuntimeException::class, 'Preview stopped.');
    expect($state)->toEqual($before);
});
