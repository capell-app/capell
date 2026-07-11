<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Schemas;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class UserSchemaContextData extends Data
{
    /**
     * @param  array<int, string>  $roleNames
     */
    public function __construct(
        public string $operation,
        public ?Model $record = null,
        public array $roleNames = [],
        public string $schemaType = 'default',
        public ?string $resourceName = null,
    ) {}

    /**
     * @param  array<int, string>  $roleNames
     */
    public static function forCreate(array $roleNames = [], string $schemaType = 'default', ?string $resourceName = null): self
    {
        return new self('create', null, $roleNames, $schemaType, $resourceName);
    }

    /**
     * @param  array<int, string>  $roleNames
     */
    public static function forEdit(Model $record, array $roleNames, string $schemaType, ?string $resourceName = null): self
    {
        return new self('edit', $record, $roleNames, $schemaType, $resourceName);
    }

    public function hasRole(string $roleName): bool
    {
        return in_array($roleName, $this->roleNames, true);
    }

    public function isSchemaType(string $schemaType): bool
    {
        return $this->schemaType === $schemaType;
    }
}
