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
        Schema::create(TableName::resolve('capell_upgrade_log'), function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('key');
            $table->string('package')->nullable();
            $table->string('status');
            $table->timestamp('ran_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['type', 'key', 'status', 'ran_at'], TableName::namedIndex('capell_upgrade_log', 'capell_upgrade_log_lookup_idx'));
            $table->index(['package'], TableName::namedIndex('capell_upgrade_log', 'capell_upgrade_log_package_idx'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_upgrade_log'));
    }
};
