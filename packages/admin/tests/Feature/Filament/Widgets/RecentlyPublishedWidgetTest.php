<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\RecentlyPublishedDataProvider;
use Capell\Admin\Data\Dashboard\RecentlyPublishedData;
use Capell\Admin\Data\Dashboard\RecentlyPublishedItemData;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
use Override;
use Spatie\LaravelData\DataCollection;
use Spatie\Permission\Models\Role;

uses(CreatesAdminUser::class)
    ->group('widget');

beforeEach(function (): void {
    Role::findOrCreate(config('capell.roles.editor', 'editor'));
});

it('renders for an authenticated editor', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $this->actingAs($user);

    app()->instance(RecentlyPublishedDataProvider::class, new class implements RecentlyPublishedDataProvider
    {
        #[Override]
        public function build(int $limit): RecentlyPublishedData
        {
            return new RecentlyPublishedData(items: RecentlyPublishedItemData::collect([
                new RecentlyPublishedItemData(pageId: 1, title: 'Published page', siteName: 'Main site', publishedAt: null, editUrl: null),
            ], DataCollection::class));
        }
    });

    Livewire::test(RecentlyPublishedFilamentWidget::class)->assertOk()->assertSee('Published page');
});

it('does not render an empty recently published panel', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));
    $this->actingAs($user);

    expect(RecentlyPublishedFilamentWidget::canView())->toBeFalse();
});
