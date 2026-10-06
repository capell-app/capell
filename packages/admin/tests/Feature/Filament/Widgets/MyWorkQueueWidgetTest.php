<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Dashboard\MyWorkQueueDataProvider;
use Capell\Admin\Data\Dashboard\MyWorkItemData;
use Capell\Admin\Data\Dashboard\MyWorkQueueData;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Auth\Authenticatable;
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

    app()->instance(MyWorkQueueDataProvider::class, new class implements MyWorkQueueDataProvider
    {
        #[Override]
        public function build(Authenticatable $user, int $limit): MyWorkQueueData
        {
            return new MyWorkQueueData(items: MyWorkItemData::collect([
                new MyWorkItemData(pageId: 1, title: 'Draft page', kind: 'draft', editUrl: null, scheduledAt: null, updatedAt: null),
            ], DataCollection::class));
        }
    });

    Livewire::test(MyWorkQueueFilamentWidget::class)->assertOk();
});

it('does not render an empty queue panel', function (): void {
    $user = $this->createUser();
    $user->assignRole(config('capell.roles.editor', 'editor'));

    $this->actingAs($user);

    expect(MyWorkQueueFilamentWidget::canView())->toBeFalse();
});

it('returns empty data for guests', function (): void {
    $widget = new MyWorkQueueFilamentWidget;

    expect($widget->data()->items->count())->toBe(0);
});
