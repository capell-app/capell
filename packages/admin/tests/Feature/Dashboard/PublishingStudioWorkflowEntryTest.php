<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

it('does not render a custom publishing studio entry on the core dashboard', function (): void {
    Auth::login(test()->createUserWithRole('super_admin'));
    Site::factory()->createOne();

    Livewire::test(CapellDashboard::class)
        ->assertDontSee('Open Publishing Studio');
});

it('does not turn the core dashboard into a parallel publishing dashboard', function (): void {
    Auth::login(test()->createUserWithRole('super_admin'));
    Site::factory()->createOne();

    Livewire::test(CapellDashboard::class)
        ->assertDontSee('Drafting')
        ->assertDontSee('Review')
        ->assertDontSee('Scheduling')
        ->assertDontSee('Published history')
        ->assertDontSee('Recovery');
});
