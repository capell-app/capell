<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Capell\Core\Models\Page;
use Capell\Tests\Fixtures\Models\User;

it('hides the user navigation badge by default even when counts are enabled', function (): void {
    User::factory()->createOne();
    config()->set('capell-admin.navigation_badge_counts', true);

    expect(UserResource::getNavigationBadge())->toBeNull()
        ->and(config('capell-admin.resources.user.navigation_badge'))->toBeFalse();
});

it('hides the page navigation badge by default even when counts are enabled', function (): void {
    Page::factory()->createOne();
    config()->set('capell-admin.navigation_badge_counts', true);

    expect(PageResource::getNavigationBadge())->toBeNull()
        ->and(config('capell-admin.resources.page.navigation_badge'))->toBeFalse();
});

it('shows the user navigation badge count when the resource setting is enabled', function (): void {
    User::factory()->createOne();
    config()->set('capell-admin.navigation_badge_counts', true);
    config()->set('capell-admin.resources.user.navigation_badge', true);

    expect(UserResource::getNavigationBadge())->not->toBeNull();
});

it('hides every resource navigation badge while counts are disabled', function (): void {
    User::factory()->createOne();
    config()->set('capell-admin.navigation_badge_counts', false);
    config()->set('capell-admin.resources.user.navigation_badge', true);

    expect(UserResource::getNavigationBadge())->toBeNull();
});
