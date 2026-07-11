<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ExtensionAuditEventData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $packageName,
        public readonly string $event,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?string $message = null,
        public readonly ?string $actorName = null,
    ) {}
}
