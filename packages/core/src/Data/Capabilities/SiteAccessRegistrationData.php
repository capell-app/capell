<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class SiteAccessRegistrationData extends Data
{
    public function __construct(
        public int $registrationId,
        public string $email,
        public CarbonImmutable $approvedAt,
        public ?string $name = null,
        public ?int $siteId = null,
        public int|string|null $userId = null,
    ) {}
}
