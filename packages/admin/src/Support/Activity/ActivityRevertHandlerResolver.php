<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;

final class ActivityRevertHandlerResolver
{
    public function resolve(ActivityRevertSelectionData $selection): ActivityRevertHandler
    {
        $handlers = collect(app()->tagged(ActivityRevertHandler::TAG))
            ->filter(fn (object $handler): bool => $handler instanceof ActivityRevertHandler)
            ->sortByDesc(fn (ActivityRevertHandler $handler): int => $handler->priority());

        foreach ($handlers as $handler) {
            if ($handler->supports($selection)) {
                return $handler;
            }
        }

        return resolve(DefaultActivityRevertHandler::class);
    }
}
