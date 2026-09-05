<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Agent;

use Illuminate\Contracts\Auth\Authenticatable;
use Spatie\LaravelData\Data;

final class AgentAdminToolInvocationData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $tool,
        public readonly array $payload,
        public readonly int $siteId,
        public readonly Authenticatable $user,
        public readonly ?string $sessionId = null,
    ) {}
}
