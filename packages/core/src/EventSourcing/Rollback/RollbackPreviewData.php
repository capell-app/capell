<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Spatie\LaravelData\Data;

/**
 * The structured result of previewing a rollback to a given version: the diff
 * (per state section) plus the validation outcome. Built entirely in core
 * without writing to the database, so the UI can show "what would change" and
 * "why it might be blocked" before anyone commits.
 *
 * @property list<RollbackFieldChangeData> $fields
 * @property list<RollbackIssueData> $issues
 */
final class RollbackPreviewData extends Data
{
    /**
     * @param  list<RollbackFieldChangeData>  $fields
     * @param  list<RollbackIssueData>  $issues
     * @param  array<string, mixed>  $targetState
     */
    public function __construct(
        public readonly int $toVersion,
        public readonly int $currentVersion,
        public readonly array $fields,
        public readonly array $issues,
        public readonly array $targetState,
    ) {}

    public function isBlocked(): bool
    {
        return $this->blockingIssues() !== [];
    }

    public function hasChanges(): bool
    {
        foreach ($this->fields as $field) {
            if ($field->changeType !== RollbackChangeType::Unchanged) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<RollbackIssueData>
     */
    public function blockingIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (RollbackIssueData $issue): bool => $issue->isBlocking(),
        ));
    }

    /**
     * @return list<RollbackIssueData>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->issues,
            static fn (RollbackIssueData $issue): bool => ! $issue->isBlocking(),
        ));
    }
}
