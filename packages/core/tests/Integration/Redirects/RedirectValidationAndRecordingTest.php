<?php

declare(strict_types=1);

use Capell\Core\Actions\Redirects\ValidateRedirectAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Redirects\PageUrlRedirectUrlRecorder;

it('records automatic redirects to the current page URL and does not overwrite existing URLs', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->createOne();
    PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->delete();

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->createOne(['url' => '/current-page']);

    $recorder = new PageUrlRedirectUrlRecorder;
    $recorder->record($page, $language, '/old-page');
    $recorder->record($page, $language, '/old-page');

    $redirect = PageUrl::query()
        ->where('url', '/old-page')
        ->where('type', UrlTypeEnum::Redirect)
        ->sole();

    expect(PageUrl::query()->where('url', '/old-page')->count())->toBe(1)
        ->and($redirect->target_url)->toBe('/current-page')
        ->and($redirect->status_code)->toBe(RedirectStatusCodeEnum::Permanent)
        ->and($redirect->is_manual)->toBeFalse()
        ->and($redirect->status)->toBeTrue();
});

it('validates redirect safety loops chains duplicates and automatic redirect conflicts together', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations($language)->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->createOne();

    $duplicate = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->manualRedirect()
        ->createOne([
            'url' => '/duplicate',
            'target_url' => '/target',
        ]);

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->manualRedirect()
        ->createOne([
            'url' => '/target',
            'target_url' => '/final',
        ]);

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->manualRedirect()
        ->createOne([
            'url' => '/loop-next',
            'target_url' => '/loop-start',
        ]);

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->manualRedirect()
        ->page($page)
        ->createOne([
            'url' => '/auto-conflict',
            'target_url' => '/new-target',
            'is_manual' => false,
        ]);

    $duplicateResult = ValidateRedirectAction::run(
        sourceUrl: '/duplicate',
        targetUrl: '/target',
        siteId: $site->getKey(),
        languageId: $language->getKey(),
        statusCode: 999,
    );
    $loopResult = ValidateRedirectAction::run(
        sourceUrl: '/loop-start',
        targetUrl: '/loop-next',
        siteId: $site->getKey(),
        languageId: $language->getKey(),
    );
    $conflictResult = ValidateRedirectAction::run(
        sourceUrl: '/auto-conflict',
        targetUrl: '/safe-target',
        siteId: $site->getKey(),
        languageId: $language->getKey(),
        excludeId: $duplicate->getKey(),
        validateDuplicateSource: false,
    );
    $unsafeResult = ValidateRedirectAction::run(
        sourceUrl: 'relative-source',
        targetUrl: '//evil.test/path',
        siteId: $site->getKey(),
        languageId: $language->getKey(),
    );

    expect($duplicateResult['errors'])->toContain(
        __('capell::message.redirect_duplicate_source'),
        __('capell::message.redirect_invalid_status_code'),
    )
        ->and($duplicateResult['warnings'][0] ?? null)->toContain('/final')
        ->and($loopResult['errors'])->toContain(__('capell::message.redirect_loop_detected'))
        ->and($conflictResult['errors'])->toBeEmpty()
        ->and($conflictResult['warnings'])->toContain(__('capell::message.redirect_auto_conflict'))
        ->and($unsafeResult['errors'])->toContain(
            __('capell::message.redirect_source_must_start_with_slash'),
            __('capell::message.redirect_target_invalid'),
        );
});
