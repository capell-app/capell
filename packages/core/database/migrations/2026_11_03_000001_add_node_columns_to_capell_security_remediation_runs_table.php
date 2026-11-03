<?php

declare(strict_types=1);

use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(TableName::resolve('capell_security_remediation_runs'), function (Blueprint $table): void {
            $table->json('expected_nodes')->nullable();
            $table->json('acknowledged_nodes')->nullable();
            $table->json('node_outcomes')->nullable();
            // applied_pending_nodes no longer fits the original 20-character column.
            $table->string('outcome', SecurityRemediationRun::OUTCOME_LENGTH)->change();
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('capell_security_remediation_runs'), function (Blueprint $table): void {
            $table->dropColumn(['expected_nodes', 'acknowledged_nodes', 'node_outcomes']);
            $table->string('outcome', 20)->change();
        });
    }
};
