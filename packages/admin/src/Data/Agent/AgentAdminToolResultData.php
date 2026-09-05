<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Agent;

use Override;
use Spatie\LaravelData\Data;

final class AgentAdminToolResultData extends Data
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $mode,
        public readonly string $tool,
        public readonly array $data = [],
        public readonly ?string $confirmationToken = null,
        public readonly ?string $message = null,
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'mode' => $this->mode,
            'tool' => $this->tool,
            'data' => $this->data,
            'confirmationToken' => $this->confirmationToken,
            'message' => $this->message,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
