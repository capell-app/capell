<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdatePageUrlAction;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\EventSourcing\Aggregates\PageAggregate;
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;

it('updates page URL and persists', function (): void {
    $site = Site::factory()->createOne();
    $lang = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create(['name' => 'About']);

    $translation = Translation::factory()
        ->state([
            'translatable_type' => $page->getMorphClass(),
            'translatable_id' => $page->id,
            'language_id' => $lang->id,
            'title' => $page->name,
        ])
        ->create();

    UpdatePageUrlAction::run($site, $translation);

    $translation->pageUrl->refresh();

    expect($translation->pageUrl->url)->toBe('/about');
});

beforeEach(function (): void {
    $this->site = Site::factory()->createOne();
    $this->language = $this->site->language;
    $this->page = Page::factory()->site($this->site)->createOne();
    $this->translation = Model::withoutEvents(fn (): Translation => Translation::factory()
        ->translatable($this->page)
        ->language($this->language)
        ->slug('current')
        ->createOne());
});

it('updates only the canonical URL while preserving its own redirect', function (string $redirectPath, string $slug, bool $redirectFirst): void {
    $attributes = [
        'pageable_type' => $this->page->getMorphClass(),
        'pageable_id' => $this->page->getKey(),
        'site_id' => $this->site->getKey(),
        'language_id' => $this->language->getKey(),
        'status' => true,
    ];
    $createRedirect = fn (): PageUrl => PageUrl::factory()->createOne([
        ...$attributes,
        'url' => $redirectPath,
        'target_url' => '/current',
        'type' => UrlTypeEnum::Redirect,
    ]);
    $createCanonical = fn (): PageUrl => PageUrl::factory()->createOne([
        ...$attributes,
        'url' => '/current',
        'type' => null,
    ]);

    // Rollback replays rows silently and can leave a redirect before the canonical row.
    [$canonical, $redirect] = Model::withoutEvents(function () use ($redirectFirst, $createRedirect, $createCanonical): array {
        if ($redirectFirst) {
            $redirect = $createRedirect();

            return [$createCanonical(), $redirect];
        }

        return [$createCanonical(), $createRedirect()];
    });
    $redirectAttributes = expectPresent($redirect->fresh())->getAttributes();

    $this->translation->meta = ['slug' => $slug];
    $this->translation->saveQuietly();
    UpdatePageUrlAction::run($this->site, $this->translation);

    expect(expectPresent($redirect->fresh())->getAttributes())->toBe($redirectAttributes);

    $this->translation->save();
    $this->page->refresh()->save();

    expect($canonical->fresh())->url->toBe('/' . $slug)->type->toBeNull()
        ->and($redirect->fresh())->url->toBe($redirectPath)->type->toBe(UrlTypeEnum::Redirect)
        ->and($this->page->pageUrls()->whereNull('type')->count())->toBe(1);
})->with([
    'same path unchanged redirect first' => ['/current', 'current', true],
    'same path changed redirect first' => ['/current', 'changed', true],
    'other path unchanged redirect first' => ['/previous', 'current', true],
    'other path changed redirect first' => ['/previous', 'changed', true],
    'own redirect reclaimed redirect first' => ['/previous', 'previous', true],
    'same path unchanged canonical first' => ['/current', 'current', false],
    'same path changed canonical first' => ['/current', 'changed', false],
    'other path unchanged canonical first' => ['/previous', 'current', false],
    'other path changed canonical first' => ['/previous', 'changed', false],
    'own redirect reclaimed canonical first' => ['/previous', 'previous', false],
]);

it('creates a canonical URL instead of overwriting an existing typed URL', function (UrlTypeEnum $type): void {
    $typedUrl = PageUrl::factory()->page($this->page)->site($this->site)->language($this->language)
        ->createOne(['url' => '/previous', 'type' => $type]);

    UpdatePageUrlAction::run($this->site, $this->translation);

    expect($typedUrl->fresh())->url->toBe('/previous')->type->toBe($type)
        ->and($this->page->pageUrls()->whereNull('type')->firstOrFail())->url->toBe('/current');
})->with([UrlTypeEnum::Alias, UrlTypeEnum::Redirect]);

it('saves after restoring an older slug with a redirect preceding the canonical URL', function (): void {
    $redirect = PageUrl::factory()->page($this->page)->site($this->site)->language($this->language)
        ->createOne(['url' => '/legacy', 'target_url' => '/current', 'type' => UrlTypeEnum::Redirect]);
    $canonical = PageUrl::factory()->page($this->page)->site($this->site)->language($this->language)
        ->createOne(['url' => '/current', 'type' => null]);
    $serializer = $this->page->eventSourcedSerializer();
    PageAggregate::retrieve($this->page->uuid)->recordRevision($serializer->capture($this->page))->persist();
    $targetVersion = resolve(RollbackService::class)->currentVersion($this->page->uuid);

    $this->translation->forceFill(['meta' => ['slug' => 'newer']])->saveQuietly();
    $canonical->forceFill(['url' => '/newer'])->saveQuietly();
    PageAggregate::retrieve($this->page->uuid)->recordRevision($serializer->capture($this->page))->persist();

    ApplyRollbackAction::run($this->page->fresh(), $targetVersion);

    // Restore identifies rows by path and can recreate the older canonical row.
    $canonical = $this->page->pageUrls()->whereNull('type')->firstOrFail();
    expect(expectPresent($this->translation->fresh())->slug)->toBe('current')
        ->and($canonical->fresh()->url)->toBe('/current')
        ->and($redirect->fresh()->type)->toBe(UrlTypeEnum::Redirect);

    $this->page->refresh()->forceFill(['name' => 'Saved after rollback'])->save();
    $this->translation->refresh()->forceFill(['meta' => ['slug' => 'after-rollback']])->save();
    $this->page->refresh()->save();

    expect($canonical->fresh()->url)->toBe('/after-rollback')
        ->and($redirect->fresh())->url->toBe('/legacy')->type->toBe(UrlTypeEnum::Redirect);
});

it('rejects another page URL at the target path without changing either row', function (?UrlTypeEnum $type, bool $existingCanonical): void {
    $otherPage = Page::factory()->site($this->site)->createOne();
    $conflict = PageUrl::factory()->page($otherPage)->site($this->site)->language($this->language)
        ->createOne(['url' => '/current', 'type' => $type, 'target_url' => $type instanceof UrlTypeEnum ? '/elsewhere' : null]);
    $canonical = $existingCanonical
        ? PageUrl::factory()->page($this->page)->site($this->site)->language($this->language)
            ->createOne(['url' => '/original', 'type' => null])
        : null;

    expect(function (): void {
        UpdatePageUrlAction::run($this->site, $this->translation);
    })
        ->toThrow(PageUrlCollisionException::class)
        ->and($conflict->fresh())->url->toBe('/current')->type->toBe($type)
        ->and($canonical?->fresh()->url)->toBe($existingCanonical ? '/original' : null)
        ->and($this->page->pageUrls()->count())->toBe($existingCanonical ? 1 : 0);
})->with([
    'another page canonical, create' => [null, false],
    'another page canonical, update' => [null, true],
    'another page redirect, create' => [UrlTypeEnum::Redirect, false],
    'another page redirect, update' => [UrlTypeEnum::Redirect, true],
]);

it('rejects an ownerless manual redirect at the target path', function (): void {
    $redirect = PageUrl::factory()->manualRedirect()->site($this->site)->language($this->language)
        ->createOne(['url' => '/current']);

    expect(function (): void {
        UpdatePageUrlAction::run($this->site, $this->translation);
    })
        ->toThrow(PageUrlCollisionException::class)
        ->and($redirect->fresh()->url)->toBe('/current')
        ->and($this->page->pageUrls()->count())->toBe(0);
});

it('does not treat the same numeric ID with a different morph type as the same page', function (): void {
    $conflict = Model::withoutEvents(fn (): PageUrl => PageUrl::factory()
        ->page($this->page)->site($this->site)->language($this->language)
        ->createOne(['url' => '/current', 'pageable_type' => 'other-page-type', 'type' => UrlTypeEnum::Redirect]));

    expect(function (): void {
        UpdatePageUrlAction::run($this->site, $this->translation);
    })
        ->toThrow(PageUrlCollisionException::class)
        ->and(expectPresent($conflict->fresh())->url)->toBe('/current');
});

it('does not collide with another redirect destination when its source path differs', function (): void {
    $otherPage = Page::factory()->site($this->site)->createOne();
    $redirect = PageUrl::factory()->page($otherPage)->site($this->site)->language($this->language)
        ->createOne(['url' => '/elsewhere', 'target_url' => '/current', 'type' => UrlTypeEnum::Redirect]);

    UpdatePageUrlAction::run($this->site, $this->translation);

    expect($this->page->pageUrls()->whereNull('type')->firstOrFail()->url)->toBe('/current')
        ->and($redirect->fresh())->url->toBe('/elsewhere')->target_url->toBe('/current');
});

it('keeps canonical lookup scoped to the site and language', function (): void {
    $otherLanguage = Language::factory()->createOne(['code' => 'cy']);
    $otherSite = Site::factory()->createOne();
    $otherLanguageUrl = PageUrl::factory()->page($this->page)->site($this->site)->language($otherLanguage)
        ->createOne(['url' => '/other-language']);
    $otherSiteUrl = Model::withoutEvents(fn (): PageUrl => PageUrl::factory()
        ->page($this->page)->site($otherSite)->language($this->language)
        ->createOne(['url' => '/other-site']));

    UpdatePageUrlAction::run($this->site, $this->translation);

    expect($otherLanguageUrl->fresh()->url)->toBe('/other-language')
        ->and(expectPresent($otherSiteUrl->fresh())->url)->toBe('/other-site')
        ->and($this->page->pageUrls()->where('site_id', $this->site->id)
            ->where('language_id', $this->language->id)->whereNull('type')->firstOrFail()->url)->toBe('/current');
});
