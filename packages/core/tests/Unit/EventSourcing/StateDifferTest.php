<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\RollbackChangeType;
use Capell\Core\EventSourcing\Rollback\Support\StateDiffer;

it('reports distinct non-encodable state values as modified', function (): void {
    $current = ['translations' => [['content' => "\xB1"]]];
    $target = ['translations' => [['content' => "\xB2"]]];

    $changes = (new StateDiffer)->diff($current, $target);

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->path)->toBe('translations')
        ->and($changes[0]->changeType)->toBe(RollbackChangeType::Modified)
        ->and($changes[0]->before)->toBe($current['translations'])
        ->and($changes[0]->after)->toBe($target['translations']);
});

it('classifies exact, added, and removed state sections', function (): void {
    $changes = (new StateDiffer)->diff(
        current: [
            'attributes' => ['name' => 'Current'],
            'translations' => [['title' => 'Shared']],
        ],
        target: [
            'translations' => [['title' => 'Shared']],
            'pageUrls' => [['url' => '/target']],
        ],
        includeUnchanged: true,
    );

    expect($changes)->toHaveCount(3)
        ->and($changes[0]->path)->toBe('attributes')
        ->and($changes[0]->changeType)->toBe(RollbackChangeType::Removed)
        ->and($changes[1]->path)->toBe('translations')
        ->and($changes[1]->changeType)->toBe(RollbackChangeType::Unchanged)
        ->and($changes[2]->path)->toBe('pageUrls')
        ->and($changes[2]->changeType)->toBe(RollbackChangeType::Added);
});
