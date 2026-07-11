<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Configurators;

use Capell\Admin\Contracts\ConfiguratorTypeEnumInterface;
use Spatie\LaravelData\Data;

final class ConfiguratorContextData extends Data
{
    public function __construct(
        public ConfiguratorTypeEnumInterface $target,
        public string $operation,
        public ?string $typeKey = null,
        public ?string $resourceName = null,
        public bool $embeddedSelectEdit = false,
        public ?string $recordName = null,
        public ?string $recordKey = null,
        public ?string $recordType = null,
    ) {}

    public static function forCreate(ConfiguratorTypeEnumInterface $target, ?string $typeKey, ?string $resourceName = null): self
    {
        return new self($target, 'create', $typeKey, $resourceName);
    }

    public static function forEdit(ConfiguratorTypeEnumInterface $target, ?string $resourceName = null): self
    {
        return new self($target, 'edit', null, $resourceName);
    }

    public static function forEmbeddedSelectEdit(
        ConfiguratorTypeEnumInterface $target,
        ?string $resourceName = null,
        ?string $recordName = null,
        ?string $recordKey = null,
        ?string $recordType = null,
    ): self {
        return new self(
            target: $target,
            operation: 'edit',
            resourceName: $resourceName,
            embeddedSelectEdit: true,
            recordName: $recordName,
            recordKey: $recordKey,
            recordType: $recordType,
        );
    }
}
