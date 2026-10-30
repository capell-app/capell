<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-extension auto-update policy.
 *
 * Defaults to `none` on purpose: an upgrade that silently switched existing
 * sites into taking code they never asked for would be exactly the behaviour
 * this setting exists to make deliberate.
 */
return new class extends Migration
{
    public function up(): void
    {
        TableName::existsAndIsOwned('capell_extensions', '2026_05_10_190832_24_create_capell_extensions_table');

        if (Schema::hasColumn(TableName::resolve('capell_extensions'), 'auto_update_policy')) {
            return;
        }

        Schema::table(TableName::resolve('capell_extensions'), function (Blueprint $table): void {
            $table->string('auto_update_policy')
                ->default(ExtensionAutoUpdatePolicyEnum::None->value)
                ->index(TableName::index('capell_extensions', 'auto_update_policy', 'index'));
        });
    }

    public function down(): void
    {
        TableName::existsAndIsOwned('capell_extensions', '2026_05_10_190832_24_create_capell_extensions_table');

        if (! Schema::hasColumn(TableName::resolve('capell_extensions'), 'auto_update_policy')) {
            return;
        }

        Schema::table(TableName::resolve('capell_extensions'), function (Blueprint $table): void {
            $table->dropIndex(TableName::index('capell_extensions', ['auto_update_policy'], 'index'));
            $table->dropColumn('auto_update_policy');
        });
    }
};
