<?php

declare(strict_types=1);

use Capell\Admin\Data\PaletteCommandData;
use Capell\Admin\Facades\CapellAdmin;

it('registers and retrieves palette commands', function (): void {
    $command = new PaletteCommandData(
        id: 'test.command',
        label: 'Test Command',
        url: '/admin/test',
    );

    CapellAdmin::registerCommand($command);

    $commands = CapellAdmin::getPaletteCommands();

    expect($commands)->toHaveKey('test.command')
        ->and($commands['test.command']->label)->toBe('Test Command')
        ->and($commands['test.command']->url)->toBe('/admin/test');
});

it('returns palette commands sorted by weight', function (): void {
    $commandA = new PaletteCommandData(id: 'sort.z', label: 'Z Command', sort: 90);
    $commandB = new PaletteCommandData(id: 'sort.a', label: 'A Command', sort: 10);

    CapellAdmin::registerCommand($commandA);
    CapellAdmin::registerCommand($commandB);

    $commands = array_values(CapellAdmin::getPaletteCommands());

    $sortedLabels = array_map(fn (PaletteCommandData $cmd): string => $cmd->label, $commands);
    $firstIndex = array_search('A Command', $sortedLabels, true);
    $secondIndex = array_search('Z Command', $sortedLabels, true);

    assert(is_int($firstIndex));
    assert(is_int($secondIndex));

    expect($sortedLabels)->toContain('A Command')
        ->and($firstIndex)->toBeLessThan($secondIndex);
});

it('builds PaletteCommandData with all optional fields', function (): void {
    $command = new PaletteCommandData(
        id: 'full.command',
        label: 'Full Command',
        description: 'A description',
        url: '/admin/full',
        shortcut: 'Ctrl+F',
        group: 'Navigation',
        sort: 5,
    );

    expect($command->id)->toBe('full.command')
        ->and($command->description)->toBe('A description')
        ->and($command->shortcut)->toBe('Ctrl+F')
        ->and($command->group)->toBe('Navigation')
        ->and($command->sort)->toBe(5);
});
