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
        if (! TableName::existsAndIsOwned('marketplace_install_attempts', '2026_05_10_190837_09_create_marketplace_install_attempts_table')) {
            return;
        }

        Schema::table(TableName::resolve('marketplace_install_attempts'), function (Blueprint $table): void {
            if (! Schema::hasColumn(TableName::resolve('marketplace_install_attempts'), 'beta_acknowledged')) {
                $table->boolean('beta_acknowledged')->default(false);
            }

            if (! Schema::hasColumn(TableName::resolve('marketplace_install_attempts'), 'policy_evidence')) {
                $table->json('policy_evidence')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! TableName::existsAndIsOwned('marketplace_install_attempts', '2026_05_10_190837_09_create_marketplace_install_attempts_table')) {
            return;
        }

        Schema::table(TableName::resolve('marketplace_install_attempts'), function (Blueprint $table): void {
            $columns = collect(['beta_acknowledged', 'policy_evidence'])
                ->filter(fn (string $column): bool => Schema::hasColumn(TableName::resolve('marketplace_install_attempts'), $column))
                ->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
