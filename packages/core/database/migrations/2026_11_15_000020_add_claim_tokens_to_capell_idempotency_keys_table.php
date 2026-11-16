<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TableName::resolve('capell_idempotency_keys'), function (Blueprint $table): void {
            // Fences completion to the caller holding the current claim, so a worker whose claim expired and was
            // taken over cannot complete or release the new holder's claim.
            $table->char('claim_token', 64)->nullable()->after('status');
            // A completed result kept permanently has no expiry.
            $table->dateTime('expires_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        $table = TableName::resolve('capell_idempotency_keys');

        DB::table($table)->whereNull('expires_at')->delete();

        Schema::table($table, function (Blueprint $table): void {
            $table->dropColumn('claim_token');
            $table->dateTime('expires_at')->nullable(false)->change();
        });
    }
};
