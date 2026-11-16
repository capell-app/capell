<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per package permission whose default roles have been granted, so the grant
        // happens once: a later sync never hands back a permission an administrator removed.
        Schema::create(TableName::resolve('admin_permission_default_grants'), function (Blueprint $table): void {
            $table->id();
            $table->string('package');
            $table->string('permission');
            $table->string('guard_name');
            $table->dateTime('granted_at');
            $table->unique(['permission', 'guard_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('admin_permission_default_grants'));
    }
};
