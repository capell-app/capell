<?php

declare(strict_types=1);

use Capell\Admin\Support\Pages\DefaultPageTableStatusResolver;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('resolves published pages as live', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $page = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::parse('2026-05-20 09:00:00'),
        'visible_until' => null,
    ]);

    $status = resolve(DefaultPageTableStatusResolver::class)->resolve($page);

    expect($status->label)->toBe((string) __('capell-admin::table.page_status_published'))
        ->and($status->shortLabel)->toBe((string) __('capell-admin::table.page_status_published_short'))
        ->and($status->color)->toBe('success')
        ->and($status->date)->toBeNull();
});

it('resolves scheduled pages with a compact day label', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $page = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::parse('2026-05-27 10:00:00'),
        'visible_until' => null,
    ]);

    $status = resolve(DefaultPageTableStatusResolver::class)->resolve($page);

    expect($status->label)->toBe((string) __('capell-admin::table.page_status_scheduled'))
        ->and($status->shortLabel)->toBe('4d')
        ->and($status->tooltip)->toContain('27 May 2026')
        ->and($status->color)->toBe('warning')
        ->and($status->date?->toDateTimeString())->toBe('2026-05-27 10:00:00');
});

it('resolves sentinel draft pages as draft, not scheduled', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $page = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::now()->addYears(100),
        'visible_until' => null,
    ]);

    $status = resolve(DefaultPageTableStatusResolver::class)->resolve($page);

    expect($status->label)->toBe((string) __('capell-admin::table.page_status_draft'))
        ->and($status->shortLabel)->toBe((string) __('capell-admin::table.page_status_draft_short'))
        ->and($status->color)->toBe('gray')
        ->and($status->date)->toBeNull();
});

it('resolves expired pages', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $page = Page::factory()->createOne([
        'visible_from' => CarbonImmutable::parse('2026-05-01 09:00:00'),
        'visible_until' => CarbonImmutable::parse('2026-05-20 09:00:00'),
    ]);

    $status = resolve(DefaultPageTableStatusResolver::class)->resolve($page);

    expect($status->label)->toBe((string) __('capell-admin::table.page_status_expired'))
        ->and($status->shortLabel)->toBe((string) __('capell-admin::table.page_status_expired_short'))
        ->and($status->color)->toBe('gray');
});

it('resolves deleted pages', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-23 09:00:00'));

    $page = Page::factory()->createOne();
    $page->delete();

    $status = resolve(DefaultPageTableStatusResolver::class)->resolve($page->refresh());

    expect($status->label)->toBe((string) __('capell-admin::table.page_status_deleted'))
        ->and($status->shortLabel)->toBe((string) __('capell-admin::table.page_status_deleted_short'))
        ->and($status->color)->toBe('danger');
});
