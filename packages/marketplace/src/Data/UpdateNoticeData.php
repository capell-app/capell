<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Override;
use Spatie\LaravelData\Data;

final class UpdateNoticeData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiResponse(array $payload): self
    {
        return new self($payload);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return $this->payload;
    }
}
