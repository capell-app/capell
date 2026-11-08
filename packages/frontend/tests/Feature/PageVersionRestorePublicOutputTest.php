<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Security\PublicHtmlSafetyInspector;
use Illuminate\Support\Facades\Cache;

it('serves the restored version publicly without any version history or authoring state', function (): void {
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()
        ->language($language)
        ->withTranslations(languages: $language, siteDomainData: [
            'default' => true,
            'domain' => 'restore-output.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->create();
    $page = Page::factory()
        ->site($site)
        ->type(Blueprint::factory()->page()->create(['meta' => ['content_structure' => 'html']]))
        ->layout(Layout::factory()->default()->create())
        ->withTranslations(
            languages: $language,
            data: ['title' => 'Harbour opening times', 'content' => '<p>Open daily from nine.</p>'],
            slug: '/opening-times',
        )
        ->create();
    defer()->invoke();

    $page->load(['translations', 'pageUrls']);
    $page->save();

    $restorable = resolve(RollbackService::class)->currentVersion($page->uuid);

    $page->translations()->firstOrFail()->forceFill([
        'title' => 'Harbour closed for winter',
        'content' => '<p>Closed until spring.</p>',
    ])->save();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    ApplyRollbackAction::run($page->fresh(), $restorable);
    defer()->invoke();

    $content = (string) $this->get('http://restore-output.test/opening-times')->assertOk()->getContent();
    $revision = PageRevision::query()->where('page_uuid', $page->uuid)->orderByDesc('version')->firstOrFail();

    expect($content)->toContain('Open daily from nine.')
        ->not->toContain('Closed until spring.')
        ->not->toContain($page->uuid)
        ->not->toContain('page_revisions')
        ->not->toContain('version_source')
        ->not->toContain((string) __('capell-admin::event-sourcing.rollback_summary', ['version' => $revision->version]))
        ->and(resolve(PublicHtmlSafetyInspector::class)->containsAuthoringSurface($content))->toBeFalse();
});
