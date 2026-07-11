<?php

declare(strict_types=1);

use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;
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
