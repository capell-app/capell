<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Events\SiteCreated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\Event;

it('creates a translation for each provided language', function (): void {
    $primary = Language::factory()->createOne();
    $extra = Language::factory()->createOne();
    $site = Site::factory()->language($primary)->state(['name' => 'My Site'])->create();

    SiteCreatedAction::run($site, [
        'name' => 'My Site',
        'language_id' => $primary->id,
        'languages' => [$extra->id],
        'auto_create_pages' => [],
    ]);

    expect($site->translations()->pluck('language_id')->all())
        ->toEqualCanonicalizing([$primary->id, $extra->id]);
});

it('dispatches SiteCreated with the raw form data', function (): void {
    Event::fake([SiteCreated::class]);

    $primary = Language::factory()->createOne();
    $site = Site::factory()->language($primary)->create();

    $formData = [
        'name' => 'My Site',
        'language_id' => $primary->id,
        'auto_create_pages' => [],
        'navigations' => ['main', 'footer'],
        'pages' => [],
    ];

    SiteCreatedAction::run($site, $formData);

    Event::assertDispatched(
        SiteCreated::class,
        fn (SiteCreated $event): bool => $event->site->is($site) && $event->formData === $formData,
    );
});

it('creates domains from submitted site URLs without overwriting existing domains', function (): void {
    $primary = Language::factory()->createOne();
    $secondary = Language::factory()->createOne();
    $site = Site::factory()->language($primary)->createOne();

    SiteCreatedAction::run($site, [
        'name' => 'Domain Site',
        'language_id' => $primary->id,
        'languages' => [$secondary->id],
        'site_domains' => [
            [
                'url' => 'https://example.test/nested/path/',
                'language_id' => $secondary->id,
                'default' => true,
                'status' => false,
            ],
            [
                'url' => 'https://tenant.example.test',
                'use_host_domain' => true,
            ],
            ['missing_url' => 'ignored'],
            'ignored',
        ],
    ]);

    $domains = SiteDomain::query()->where('site_id', $site->getKey())->orderBy('id')->get();
    $firstDomain = $domains->get(0);
    $secondDomain = $domains->get(1);

    assert($firstDomain instanceof SiteDomain);
    assert($secondDomain instanceof SiteDomain);

    expect($domains)->toHaveCount(2)
        ->and($firstDomain->language_id)->toBe($secondary->id)
        ->and($firstDomain->scheme)->toBe('https')
        ->and($firstDomain->domain)->toBe('example.test')
        ->and($firstDomain->path)->toBe('/nested/path')
        ->and($firstDomain->default)->toBeTrue()
        ->and($firstDomain->status)->toBeFalse()
        ->and($secondDomain->language_id)->toBe($primary->id)
        ->and($secondDomain->domain)->toBeNull();

    SiteCreatedAction::run($site->refresh(), [
        'language_id' => $primary->id,
        'site_domains' => [
            ['url' => 'https://new.example.test'],
        ],
    ]);

    expect(SiteDomain::query()->where('site_id', $site->getKey())->count())->toBe(2);
});

it('delegates selected default page creation with resolved languages', function (): void {
    Event::fake([SiteCreated::class]);

    $primary = Language::factory()->createOne();
    $secondary = Language::factory()->createOne();
    $site = Site::factory()->language($primary)->createOne();
    $captured = null;

    app()->bind('capell.admin.create-default-pages-action', function () use (&$captured): Closure {
        return function (Site $site, mixed $languages, array $pages) use (&$captured): void {
            $captured = [
                'site_id' => $site->getKey(),
                'language_ids' => $languages->pluck('id')->all(),
                'pages' => $pages,
            ];
        };
    });

    SiteCreatedAction::run($site, [
        'language_id' => $primary->id,
        'languages' => [$secondary->id, $primary->id, null],
        'auto_create_pages' => ['home', 'contact'],
    ]);

    expect($captured)->toBe([
        'site_id' => $site->getKey(),
        'language_ids' => [$primary->id, $secondary->id],
        'pages' => ['home', 'contact'],
    ]);
});
