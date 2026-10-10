<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\RepairsImplicitTimestampUpdates;
use Capell\Core\Support\Database\Platforms\MariaDbDatabasePlatform;
use Capell\Core\Support\Database\Platforms\MySqlDatabasePlatform;
use Capell\Core\Support\Database\Platforms\PostgresDatabasePlatform;
use Capell\Core\Support\Database\Platforms\SqliteDatabasePlatform;
use Capell\Tests\Support\Fakes\TimestampMetadataConnection;

it('removes timestamp updates while preserving the rest of the column definition', function (array $metadata, string $definition): void {
    foreach ([new MySqlDatabasePlatform, new MariaDbDatabasePlatform] as $platform) {
        $connection = new TimestampMetadataConnection($metadata);

        $dialect = $platform->schemaDialect();
        expect($dialect)->toBeInstanceOf(RepairsImplicitTimestampUpdates::class);
        assert($dialect instanceof RepairsImplicitTimestampUpdates);
        $dialect->dropImplicitTimestampUpdate('events', 'occurred_at', $connection);
        expect($connection->statements)->toBe(['ALTER TABLE `proof_events` MODIFY COLUMN `occurred_at` ' . $definition]);
    }
})->with([
    'legacy current timestamp' => [[
        'COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 'current_timestamp()',
        'COLUMN_COMMENT' => '', 'EXTRA' => 'on update current_timestamp()',
    ], "TIMESTAMP NOT NULL DEFAULT current_timestamp() COMMENT ''"],
    'nullable fractional precision' => [[
        'COLUMN_TYPE' => 'timestamp(6)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null,
        'COLUMN_COMMENT' => "Event's time", 'EXTRA' => 'on update CURRENT_TIMESTAMP(6)',
    ], "TIMESTAMP(6) NULL DEFAULT NULL COMMENT 'Event''s time'"],
    'mariadb sql null default' => [[
        'COLUMN_TYPE' => 'timestamp(6)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'NULL',
        'COLUMN_COMMENT' => '', 'EXTRA' => 'on update current_timestamp(6)',
    ], "TIMESTAMP(6) NULL DEFAULT NULL COMMENT ''"],
    'mysql literal default' => [[
        'COLUMN_TYPE' => 'timestamp(3)', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => '2020-01-01 00:00:00.123',
        'COLUMN_COMMENT' => 'Original event', 'EXTRA' => 'on update CURRENT_TIMESTAMP(3)',
    ], "TIMESTAMP(3) NOT NULL DEFAULT '2020-01-01 00:00:00.123' COMMENT 'Original event'"],
    'mariadb quoted literal default' => [[
        'COLUMN_TYPE' => 'timestamp', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => "'2020-01-01 00:00:00'",
        'COLUMN_COMMENT' => '', 'EXTRA' => 'on update current_timestamp()',
    ], "TIMESTAMP NOT NULL DEFAULT '2020-01-01 00:00:00' COMMENT ''"],
]);

it('leaves missing, already safe and non-timestamp columns untouched', function (?array $metadata): void {
    $connection = new TimestampMetadataConnection($metadata);

    $dialect = new MySqlDatabasePlatform()->schemaDialect();
    assert($dialect instanceof RepairsImplicitTimestampUpdates);
    $dialect->dropImplicitTimestampUpdate('events', 'occurred_at', $connection);
    expect($connection->statements)->toBe([]);
})->with([
    'missing' => [null],
    'safe timestamp' => [['COLUMN_TYPE' => 'timestamp', 'EXTRA' => '']],
    'datetime' => [['COLUMN_TYPE' => 'datetime', 'EXTRA' => 'on update current_timestamp()']],
]);

it('does not expose timestamp repair on other database families', function (): void {
    foreach ([new SqliteDatabasePlatform, new PostgresDatabasePlatform] as $platform) {
        expect($platform->schemaDialect())->not->toBeInstanceOf(RepairsImplicitTimestampUpdates::class);
    }
});

it('refuses unsupported timestamp default expressions before altering the column', function (string $default): void {
    $connection = new TimestampMetadataConnection([
        'COLUMN_TYPE' => 'timestamp(6)',
        'IS_NULLABLE' => 'NO',
        'COLUMN_DEFAULT' => $default,
        'COLUMN_COMMENT' => 'Expression default',
        'EXTRA' => 'on update current_timestamp(6)',
    ]);

    $dialect = new MySqlDatabasePlatform()->schemaDialect();
    assert($dialect instanceof RepairsImplicitTimestampUpdates);
    expect(fn () => $dialect->dropImplicitTimestampUpdate('events', 'occurred_at', $connection))
        ->toThrow(RuntimeException::class, 'unsupported TIMESTAMP default');

    expect($connection->statements)->toBe([]);
})->with([
    'parenthesised expression' => '(current_timestamp(6) + interval 1 day)',
    'unparenthesised expression' => 'current_timestamp(6) + interval 1 day',
    'other function' => 'utc_timestamp(6)',
]);
