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
        Schema::create(TableName::resolve('admin_transfer_origins'), function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_type', 32);
            $table->string('transfer_key');
            $table->string('panel');
            // The host (with any port) that served the request, where its download is sent.
            $table->string('host');
            $table->timestamps();
            // Explicit short names also fit PostgreSQL with prefixed tables.
            $table->unique(['transfer_type', 'transfer_key'], TableName::namedIndex('admin_transfer_origins', 'admin_transfer_origins_transfer'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('admin_transfer_origins'));
    }
};
