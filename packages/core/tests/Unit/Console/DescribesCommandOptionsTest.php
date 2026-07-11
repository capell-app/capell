<?php

declare(strict_types=1);

use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Illuminate\Console\Command;

it('formats one command intro detail', function (): void {
    $command = new class extends Command
    {
        use DescribesCommandOptions {
            formatCommandIntroDetails as public;
        }
    };

    expect($command->formatCommandIntroDetails(['demo content']))->toBe('demo content');
});

it('formats multiple command intro details with a final and', function (): void {
    $command = new class extends Command
    {
        use DescribesCommandOptions {
            formatCommandIntroDetails as public;
        }
    };

    expect($command->formatCommandIntroDetails([
        'a fresh database refresh',
        'demo content',
        'confirmation skipped',
    ]))->toBe('a fresh database refresh, demo content and confirmation skipped');
});
