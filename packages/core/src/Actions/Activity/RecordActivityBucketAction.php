<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final class RecordActivityBucketAction
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function execute(
        Site $site,
        string $language,
        ActivityBucketSubjectEnum $subjectType,
        string $subjectKey,
        ?CarbonImmutable $occurredAt = null,
    ): void {
        $language = trim($language);
        $subjectKey = trim($subjectKey);

        throw_if($language === '' || mb_strlen($language) > 32, InvalidArgumentException::class, 'Activity language must be between 1 and 32 characters.');
        throw_if($subjectKey === '' || mb_strlen($subjectKey) > 191, InvalidArgumentException::class, 'Activity subject must be between 1 and 191 characters.');

        $occurredAt ??= CarbonImmutable::now('UTC');
        // Floor after converting to UTC so the persisted identity is timezone-independent.
        $utcOccurredAt = $occurredAt->utc();
        $bucketStartedAt = $utcOccurredAt
            ->startOfMinute()
            ->subMinutes($utcOccurredAt->minute % 5);

        $connection = $this->database->connection();
        $table = $connection->getQueryGrammar()->wrapTable((new ActivityBucket)->getTable());
        $columns = collect([
            'site_id',
            'language',
            'subject_type',
            'subject_key',
            'bucket_started_at',
            'count',
            'created_at',
            'updated_at',
        ])->map(fn (string $column): string => $connection->getQueryGrammar()->wrap($column))->implode(', ');
        $timestamp = CarbonImmutable::now('UTC')->toDateTimeString();
        $values = [$site->getKey(), $language, $subjectType->value, $subjectKey, $bucketStartedAt->toDateTimeString(), 1, $timestamp, $timestamp];

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $identity = collect(['site_id', 'language', 'subject_type', 'subject_key', 'bucket_started_at'])
            ->map(fn (string $column): string => $connection->getQueryGrammar()->wrap($column))
            ->implode(', ');
        $countColumn = $connection->getQueryGrammar()->wrap('count');
        $updatedAtColumn = $connection->getQueryGrammar()->wrap('updated_at');
        $driver = $connection->getDriverName();

        $sql = match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'insert into %s (%s) values (%s) on duplicate key update %s = %s + 1, %s = values(%s)',
                $table,
                $columns,
                $placeholders,
                $countColumn,
                $countColumn,
                $updatedAtColumn,
                $updatedAtColumn,
            ),
            default => sprintf(
                'insert into %s (%s) values (%s) on conflict (%s) do update set %s = %s + 1, %s = excluded.%s',
                $table,
                $columns,
                $placeholders,
                $identity,
                $countColumn,
                $countColumn,
                $updatedAtColumn,
                $updatedAtColumn,
            ),
        };

        $connection->statement($sql, $values);
    }
}
