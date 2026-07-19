<?php

declare(strict_types=1);

use Capell\Core\Exceptions\UnauthorizedPublicationMutationException;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Carbon\CarbonImmutable;

it('rejects raw visibility-date writes outside an authorized scope', function (): void {
    $page = Page::factory()->create();

    expect(fn () => $page->update(['visible_from' => CarbonImmutable::now()]))
        ->toThrow(
            UnauthorizedPublicationMutationException::class,
            'Unauthorized publication-date mutation on ' . Page::class . ' for [visible_from]',
        );
});

it('permits visibility-date writes inside PublicationDateGuard::allow', function (): void {
    $page = Page::factory()->create();
    $publishedAt = CarbonImmutable::now()->startOfSecond();

    PublicationDateGuard::allow(function () use ($page, $publishedAt): void {
        $page->update(['visible_from' => $publishedAt]);
    });

    expect($page->refresh()->visible_from?->equalTo($publishedAt))->toBeTrue();
});

it('permits bulk visibility-date updates inside an authorized scope', function (): void {
    $page = Page::factory()->create();
    $publishedAt = CarbonImmutable::now()->startOfSecond();

    PublicationDateGuard::allow(
        fn (): int => Page::query()->whereKey($page->id)->update(['visible_from' => $publishedAt]),
    );

    expect($page->refresh()->visible_from?->equalTo($publishedAt))->toBeTrue();
});

it('permits saves that do not touch visibility dates', function (): void {
    $page = Page::factory()->create();

    $page->update(['name' => 'Renamed']);

    expect($page->refresh()->name)->toBe('Renamed');
});

it('supports an explicit configuration kill switch', function (): void {
    config(['capell.publishing.guard_visibility_dates' => false]);

    $page = Page::factory()->create();
    $publishedAt = CarbonImmutable::now()->startOfSecond();

    $page->update(['visible_from' => $publishedAt]);

    expect(PublicationDateGuard::enabled())->toBeFalse()
        ->and($page->refresh()->visible_from?->equalTo($publishedAt))->toBeTrue();
});
