<?php

declare(strict_types=1);

use Livewire\Component;
use Livewire\Features\SupportDisablingBackButtonCache\SupportDisablingBackButtonCache;
use Livewire\Livewire;

class TestBoundaryComponent extends Component
{
    public function render(): string
    {
        return '<div>Boundary probe</div>';
    }
}

it('renders a component directly before the next test', function (): void {
    Livewire::component('test-boundary-probe', TestBoundaryComponent::class);
    expect(Livewire::mount('test-boundary-probe'))->toContain('Boundary probe')
        ->and(SupportDisablingBackButtonCache::$disableBackButtonCache)->toBeTrue();
});

it('preserves JSON cache headers after a previous test directly rendered Livewire', function (): void {
    config(['capell-reporting.enabled' => true, 'capell-reporting.health.enabled' => true]);
    $this->getJson('/_capell/reporting/health')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
})->depends('it renders a component directly before the next test');
