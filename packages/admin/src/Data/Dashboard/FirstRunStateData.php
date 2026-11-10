<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class FirstRunStateData extends Data
{
    /**
     * @param  list<FirstRunTaskData>  $tasks
     */
    public function __construct(
        public readonly bool $isFirstRun,
        public readonly array $tasks,
    ) {}

    public static function notFirstRun(): self
    {
        return new self(false, []);
    }

    public function completedCount(): int
    {
        return count(array_filter($this->tasks, static fn (FirstRunTaskData $task): bool => $task->complete));
    }

    public function totalCount(): int
    {
        return count($this->tasks);
    }

    public function nextTask(): ?FirstRunTaskData
    {
        foreach ($this->tasks as $task) {
            if (! $task->complete) {
                return $task;
            }
        }

        return null;
    }
}
