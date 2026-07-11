<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page-type-level role restrictions.
 *
 * When a page type has rows in this table, only users holding at least one of the
 * listed roles (within the site scope) may view or edit pages of that type in the admin.
 * Types with no rows are unrestricted (subject to normal site-scoped RBAC).
 *
 * The restrictable morph allows this table to be reused for other restrictable models
 * in the future. Currently only used with type='type' (page types).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_role_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->morphs('restrictable');
            $table->foreignId('role_id')
                ->constrained('roles')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['restrictable_type', 'restrictable_id', 'role_id'], 'restrictable_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_role_restrictions');
    }
};
