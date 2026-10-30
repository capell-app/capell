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
        TableName::existsAndIsOwned('capell_extensions', '2026_05_10_190832_24_create_capell_extensions_table');

        if (Schema::hasColumn(TableName::resolve('capell_extensions'), 'provider_recovery_state')) {
            return;
        }

        Schema::table(TableName::resolve('capell_extensions'), function (Blueprint $table): void {
            $table->string('provider_recovery_state')
                ->default('healthy')
                ->index(TableName::index('capell_extensions', 'provider_recovery_state', 'index'));
            $table->string('provider_recovery_provider')->nullable();
            $table->text('provider_recovery_reason')->nullable();
            $table->timestamp('provider_recovery_at')->nullable();
            $table->json('provider_recovery_events')->nullable();
        });
    }

    public function down(): void
    {
        TableName::existsAndIsOwned('capell_extensions', '2026_05_10_190832_24_create_capell_extensions_table');

        if (! Schema::hasColumn(TableName::resolve('capell_extensions'), 'provider_recovery_state')) {
            return;
        }

        Schema::table(TableName::resolve('capell_extensions'), function (Blueprint $table): void {
            $table->dropIndex(TableName::index('capell_extensions', ['provider_recovery_state'], 'index'));
            $table->dropColumn([
                'provider_recovery_state',
                'provider_recovery_provider',
                'provider_recovery_reason',
                'provider_recovery_at',
                'provider_recovery_events',
            ]);
        });
    }
};
