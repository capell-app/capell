<?php

declare(strict_types=1);

use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Admin\Data\RecordStateData;
use Filament\Support\Icons\Heroicon;

it('renders visible accessible state labels and relationship counts', function (): void {
    $html = view('capell-admin::components.record-state-summary', [
        'states' => [
            new RecordStateData(
                key: 'no_active_url',
                label: 'No active URL',
                description: 'Visitors cannot reach this page.',
                color: 'danger',
                icon: Heroicon::OutlinedEyeSlash,
                priority: 10,
            ),
        ],
        'relationships' => [
            new RecordRelationshipCountData(
                key: 'children',
                label: 'Children',
                count: 3,
            ),
        ],
    ])->render();

    expect($html)
        ->toContain('No active URL')
        ->toContain('Visitors cannot reach this page.')
        ->toContain('Children: 3')
        ->toContain('aria-hidden="true"');
});

it('renders state metadata in custom select options without changing the base option contract', function (): void {
    $html = view('capell-admin::components.forms.select-option', [
        'label' => 'Homepage',
        'states' => [
            new RecordStateData(
                key: 'disabled',
                label: 'Disabled',
                color: 'danger',
                icon: Heroicon::OutlinedPauseCircle,
                priority: 10,
            ),
        ],
    ])->render();

    expect($html)
        ->toContain('Homepage')
        ->toContain('Disabled')
        ->toContain('select-option-label')
        ->not->toContain('<span class="mt-1 flex flex-wrap gap-1">');
});
