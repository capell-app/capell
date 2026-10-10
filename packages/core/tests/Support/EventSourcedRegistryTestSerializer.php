<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\EventSourcing\Contracts\EventSourcedStateSerializer;
use Illuminate\Database\Eloquent\Model;
use Override;

final class EventSourcedRegistryTestSerializer implements EventSourcedStateSerializer
{
    #[Override]
    public function capture(Model $model): array
    {
        return [];
    }

    #[Override]
    public function restore(Model $model, array $state): void {}
}
