<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('capell_extensions', 'provider_recovery_state')) {
            return;
        }

        Schema::table('capell_extensions', function (Blueprint $table): void {
            $table->string('provider_recovery_state')
                ->default('healthy')
                ->index();
            $table->string('provider_recovery_provider')->nullable();
            $table->text('provider_recovery_reason')->nullable();
            $table->timestamp('provider_recovery_at')->nullable();
            $table->json('provider_recovery_events')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('capell_extensions', 'provider_recovery_state')) {
            return;
        }

        Schema::table('capell_extensions', function (Blueprint $table): void {
            $table->dropIndex(['provider_recovery_state']);
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
