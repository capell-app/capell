<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_install_attempts')) {
            return;
        }

        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_install_attempts', 'beta_acknowledged')) {
                $table->boolean('beta_acknowledged')->default(false);
            }

            if (! Schema::hasColumn('marketplace_install_attempts', 'policy_evidence')) {
                $table->json('policy_evidence')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketplace_install_attempts')) {
            return;
        }

        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            $columns = collect(['beta_acknowledged', 'policy_evidence'])
                ->filter(fn (string $column): bool => Schema::hasColumn('marketplace_install_attempts', $column))
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
