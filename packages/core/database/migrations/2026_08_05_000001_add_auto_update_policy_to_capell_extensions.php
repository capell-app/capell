<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
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
        if (Schema::hasColumn('capell_extensions', 'auto_update_policy')) {
            return;
        }

        Schema::table('capell_extensions', function (Blueprint $table): void {
            $table->string('auto_update_policy')
                ->default(ExtensionAutoUpdatePolicyEnum::None->value)
                ->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('capell_extensions', 'auto_update_policy')) {
            return;
        }

        Schema::table('capell_extensions', function (Blueprint $table): void {
            $table->dropIndex(['auto_update_policy']);
            $table->dropColumn('auto_update_policy');
        });
    }
};
