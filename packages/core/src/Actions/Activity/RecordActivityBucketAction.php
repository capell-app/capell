<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Activity;

use Capell\Core\Enums\ActivityBucketSubjectEnum;
use Capell\Core\Models\ActivityBucket;
use Capell\Core\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

final class RecordActivityBucketAction
{
    public function __construct(private readonly ConnectionInterface $database) {}

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
        $bucketStartedAt = $occurredAt
            ->utc()
            ->startOfMinute()
            ->subMinutes($occurredAt->minute % 5);

        $table = $this->database->getQueryGrammar()->wrapTable((new ActivityBucket)->getTable());
        $columns = collect([
            'site_id',
            'language',
            'subject_type',
            'subject_key',
            'bucket_started_at',
            'count',
        ])->map(fn (string $column): string => $this->database->getQueryGrammar()->wrap($column))->implode(', ');
        $values = [$site->getKey(), $language, $subjectType->value, $subjectKey, $bucketStartedAt->toDateTimeString(), 1];

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $identity = collect(['site_id', 'language', 'subject_type', 'subject_key', 'bucket_started_at'])
            ->map(fn (string $column): string => $this->database->getQueryGrammar()->wrap($column))
            ->implode(', ');
        $countColumn = $this->database->getQueryGrammar()->wrap('count');
        $driver = $this->database->getDriverName();

        $sql = match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'insert into %s (%s) values (%s) on duplicate key update %s = %s + 1',
                $table,
                $columns,
                $placeholders,
                $countColumn,
                $countColumn,
            ),
            default => sprintf(
                'insert into %s (%s) values (%s) on conflict (%s) do update set %s = %s + 1',
                $table,
                $columns,
                $placeholders,
                $identity,
                $countColumn,
                $countColumn,
            ),
        };

        $this->database->statement($sql, $values);
    }
}
