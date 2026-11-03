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
        Schema::table(TableName::resolve('capell_security_remediation_runs'), function (Blueprint $table): void {
            $table->json('expected_nodes')->nullable();
            $table->json('acknowledged_nodes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('capell_security_remediation_runs'), function (Blueprint $table): void {
            $table->dropColumn(['expected_nodes', 'acknowledged_nodes']);
        });
    }
};
