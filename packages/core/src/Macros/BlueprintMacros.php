<?php

declare(strict_types=1);

namespace Capell\Core\Macros;

use Closure;
use Illuminate\Database\Schema\Blueprint;

/**
 * @mixin Blueprint
 */
class BlueprintMacros
{
    /**
     * @return Closure(string $column): void
     *
     * @return-closure-this Blueprint
     */
    public function visibleDates(): Closure
    {
        return function (?string $name = null): void {
            $this->timestamp($name !== null ? $name . '_from' : 'visible_from')->nullable();
            $this->timestamp($name !== null ? $name . '_until' : 'visible_until')->nullable();
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
            $this->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $this->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        };
    }
}
