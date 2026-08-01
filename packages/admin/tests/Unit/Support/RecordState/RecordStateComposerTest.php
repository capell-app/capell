<?php

declare(strict_types=1);

use Capell\Admin\Data\RecordStateData;
use Capell\Admin\Support\RecordState\RecordStateComposer;
use Filament\Support\Icons\Heroicon;

it('orders independent state dimensions by priority', function (): void {
    $states = [
        new RecordStateData(
            key: 'used',
            label: 'Used by 2 pages',
            shortLabel: null,
            description: null,
            color: 'success',
            icon: Heroicon::OutlinedLink,
            priority: 30,
        ),
        new RecordStateData(
            key: 'scheduled',
            label: 'Scheduled for 14 August',
            shortLabel: null,
            description: null,
            color: 'info',
            icon: Heroicon::OutlinedClock,
            priority: 20,
        ),
        new RecordStateData(
            key: 'no_active_url',
            label: 'No active URL',
            shortLabel: null,
            description: null,
            color: 'danger',
            icon: Heroicon::OutlinedEyeSlash,
            priority: 10,
        ),
    ];

    expect(RecordStateComposer::ordered($states)->pluck('key')->all())
        ->toBe(['no_active_url', 'scheduled', 'used']);
});

it('keeps simultaneous exceptional states and can suppress routine states', function (): void {
    $states = [
        new RecordStateData('no_active_url', 'No active URL', null, null, 'danger', null, 10),
        new RecordStateData('scheduled', 'Scheduled', null, null, 'info', null, 20),
        new RecordStateData('published', 'Published', null, null, 'success', null, 20, isExceptional: false),
    ];

    expect(RecordStateComposer::compose($states, exceptionalOnly: true)->pluck('key')->all())
        ->toBe(['no_active_url', 'scheduled']);
});
