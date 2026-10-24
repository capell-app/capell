<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_upload_grants', function (Blueprint $table): void {
            $table->id();
            $table->char('scope_hash', 64);
            $table->char('path_hash', 64);
            $table->text('path');
            $table->dateTime('expires_at');
            // Explicit short names also fit PostgreSQL with prefixed tables.
            $table->unique(['scope_hash', 'path_hash'], 'admin_upload_grants_scope_path');
            $table->index('expires_at', 'admin_upload_grants_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_upload_grants');
    }
};
