<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\FirstRunTaskContributor;
use Capell\Admin\Data\Dashboard\FirstRunTaskContextData;
use Capell\Admin\Data\Dashboard\FirstRunTaskData;
use Illuminate\Contracts\Container\Container;
use Throwable;

/** Collects getting-started tasks that packages contribute through {@see FirstRunTaskContributor::TAG}. */
final readonly class FirstRunTaskRegistry
{
    public function __construct(private Container $container) {}

    /**
     * @param  list<string>  $reservedKeys  keys the built-in tasks already use
     * @return list<FirstRunTaskData>
     */
    public function contributedTasks(FirstRunTaskContextData $context, array $reservedKeys): array
    {
        $tasks = [];
        $used = array_fill_keys($reservedKeys, true);

        foreach ($this->container->tagged(FirstRunTaskContributor::TAG) as $contributor) {
            if (! $contributor instanceof FirstRunTaskContributor) {
                continue;
            }

            try {
                $contributed = $contributor->tasks($context);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            foreach ($contributed as $task) {
                if (! $task instanceof FirstRunTaskData) {
                    continue;
                }

                if (trim($task->key) === '') {
                    continue;
                }

                if (isset($used[$task->key])) {
                    continue;
                }

                $used[$task->key] = true;
                $tasks[] = $task;
            }
        }

        return $tasks;
    }
}
