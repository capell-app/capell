<?php

declare(strict_types=1);

namespace Capell\Frontend\Tests\Fixtures;

use Capell\Frontend\Data\FrontendRenderPayload;
use Override;

/** Keeps test readers' untyped bag and typed payload on the same state. */
trait HasFrontendRenderData
{
    /** @var array<string, mixed> */
    private array $frontendData = [];

    #[Override]
    public function setFrontendData(string $key, mixed $value): self
    {
        $this->frontendData[$key] = $value;

        return $this;
    }

    #[Override]
    public function getFrontendData(?string $key = null): mixed
    {
        return $key === null ? $this->frontendData : ($this->frontendData[$key] ?? null);
    }

    #[Override]
    public function renderPayload(): FrontendRenderPayload
    {
        return FrontendRenderPayload::fromBag($this->frontendData);
    }
}
