<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;

it('force-deletes media soft-deleted past the retention window', function (): void {
    $page = Page::factory()->create();
    $stale = Media::factory()->model($page)->create(['deleted_at' => now()->subDays(40)]);
    $recent = Media::factory()->model($page)->create(['deleted_at' => now()->subDays(5)]);
    $live = Media::factory()->model($page)->create();

    artisanCommand('capell:purge-soft-deleted-media')->assertExitCode(0);

    expect(Media::withTrashed()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(Media::withTrashed()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(Media::query()->whereKey($live->id)->exists())->toBeTrue();
});

it('honours a custom --days window', function (): void {
    $sevenDaysOld = Media::factory()->model(Page::factory()->create())->create(['deleted_at' => now()->subDays(7)]);

    artisanCommand('capell:purge-soft-deleted-media', ['--days' => 3])->assertExitCode(0);

    expect(Media::withTrashed()->whereKey($sevenDaysOld->id)->exists())->toBeFalse();
});

it('deletes nothing under --pretend', function (): void {
    $stale = Media::factory()->model(Page::factory()->create())->create(['deleted_at' => now()->subDays(40)]);

    artisanCommand('capell:purge-soft-deleted-media', ['--pretend' => true])
        ->expectsOutputToContain('Would purge')
        ->assertExitCode(0);

    expect(Media::withTrashed()->whereKey($stale->id)->exists())->toBeTrue();
});
