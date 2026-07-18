<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Capell\Core\Enums\ContainerSizeEnum;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class LayoutData extends Data
{
    /**
     * @param  array<int, string>  $secondaryContainers
     */
    public function __construct(
        public ?ContainerSizeEnum $container = null,
        public array $secondaryContainers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'container' => $this->container?->value,
            'secondary_containers' => $this->secondaryContainers,
        ], static fn (array|string|null $value): bool => $value !== null && $value !== []);
    }
}
