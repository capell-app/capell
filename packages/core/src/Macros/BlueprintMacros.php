<?php

declare(strict_types=1);

namespace Capell\Core\Macros;

use Capell\Core\Support\TableName;
use Capell\Core\Support\UserKeyType;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;

/**
 * @mixin Blueprint
 */
class BlueprintMacros
{
    /**
     * @return Closure(?string=): void
     *
     * @return-closure-this Blueprint
     */
    public function visibleDates(): Closure
    {
        return function (?string $name = null): void {
            // Publication sentinels intentionally live beyond 2038; MySQL
            // TIMESTAMP cannot represent those dates.
            $this->dateTime($name !== null ? $name . '_from' : 'visible_from')->nullable();
            $this->dateTime($name !== null ? $name . '_until' : 'visible_until')->nullable();
        };
    }

    /**
     * @return Closure(string): ForeignIdColumnDefinition
     *
     * @return-closure-this Blueprint
     */
    public function foreignUserId(): Closure
    {
        return function (string $column): ForeignIdColumnDefinition {
            $definition = $this->foreignId($column);
            $definition->set('type', UserKeyType::columnType());

            return $definition;
        };
    }

    /**
     * @return Closure(): void
     *
     * @return-closure-this Blueprint
     */
    public function userstamps(): Closure
    {
        return function (): void {
            $this->foreignId('created_by')->type(UserKeyType::columnType())->nullable()->constrained('users', indexName: TableName::index(TableName::base($this->getTable()), 'created_by', 'foreign'))->nullOnDelete();
            $this->foreignId('updated_by')->type(UserKeyType::columnType())->nullable()->constrained('users', indexName: TableName::index(TableName::base($this->getTable()), 'updated_by', 'foreign'))->nullOnDelete();
            $this->foreignId('deleted_by')->type(UserKeyType::columnType())->nullable()->constrained('users', indexName: TableName::index(TableName::base($this->getTable()), 'deleted_by', 'foreign'))->nullOnDelete();
        };
    }
}
