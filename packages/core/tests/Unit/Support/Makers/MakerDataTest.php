<?php

declare(strict_types=1);

use Capell\Core\Data\Makers\MakerDefinitionData;
use Capell\Core\Data\Makers\MakerFileData;
use Capell\Core\Data\Makers\MakerInputData;
use Capell\Core\Data\Makers\MakerPreviewData;

it('serializes maker definitions for CLI and Filament', function (): void {
    $definition = new MakerDefinitionData(
        key: 'core.action',
        label: 'Action',
        description: 'Create a Capell action class',
        group: 'Core',
        icon: 'heroicon-o-bolt',
        supportsDatabaseWrites: false,
        supportsPhpWrites: true,
    );

    expect($definition->toArray())
        ->toMatchArray([
            'key' => 'core.action',
            'label' => 'Action',
            'group' => 'Core',
            'supportsPhpWrites' => true,
        ]);
});

it('keeps maker preview files typed', function (): void {
    $input = new MakerInputData(
        maker: 'core.action',
        values: ['name' => 'PublishPage'],
        dryRun: true,
        force: false,
        databaseWrites: false,
    );

    $preview = new MakerPreviewData(
        maker: 'core.action',
        files: collect([
            new MakerFileData(
                path: app_path('Actions/PublishPageAction.php'),
                operation: 'create',
                exists: false,
                writable: true,
                contents: '<?php',
            ),
        ]),
        databaseRecords: collect(),
        commands: collect(['php artisan capell:make-action PublishPage']),
        notes: collect(['Action will be auto-loadable by Composer.']),
    );

    $file = expectPresent(firstDataItem($preview->files));

    expect($input->maker)->toBe('core.action');
    expect($file->operation)->toBe('create');
    expect(firstDataItem($preview->commands))->toBe('php artisan capell:make-action PublishPage');
});
