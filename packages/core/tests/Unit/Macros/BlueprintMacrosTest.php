<?php

declare(strict_types=1);

use Capell\Core\Macros\BlueprintMacros;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;

it('creates DATETIME visibility columns for publication sentinels', function (): void {
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
    $connection->useDefaultSchemaGrammar();
    $blueprint = new Blueprint($connection, 'example');

    (new BlueprintMacros)->visibleDates()->call($blueprint);

    expect($blueprint->getColumns())->toHaveCount(2)
        ->and($blueprint->getColumns()[0]->name)->toBe('visible_from')
        ->and($blueprint->getColumns()[0]->type)->toBe('dateTime')
        ->and($blueprint->getColumns()[1]->name)->toBe('visible_until')
        ->and($blueprint->getColumns()[1]->type)->toBe('dateTime');
});
