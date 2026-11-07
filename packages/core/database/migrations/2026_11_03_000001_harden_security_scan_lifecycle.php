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
        Schema::create(TableName::resolve('capell_security_scan_runs'), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6)->nullable();
            $table->unsignedInteger('findings_count')->nullable();
            $table->string('source', 120);
        });
        Schema::table(TableName::resolve('capell_security_weakness_findings'), function (Blueprint $table): void {
            $table->ulid('first_detected_scan_id')->nullable();
            $table->ulid('last_detected_scan_id')->nullable();
            $table->string('evidence_hash', 64)->nullable();
        });
        Schema::table(TableName::resolve('capell_security_remediation_approvals'), function (Blueprint $table): void {
            $table->ulid('approved_for_scan_id')->nullable();
            $table->string('approved_fingerprint', 64)->nullable();
            $table->string('evidence_hash', 64)->nullable();
            $table->dateTime('superseded_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table(TableName::resolve('capell_security_remediation_approvals'), function (Blueprint $table): void {
            $table->dropColumn(['approved_for_scan_id', 'approved_fingerprint', 'evidence_hash', 'superseded_at']);
        });
        Schema::table(TableName::resolve('capell_security_weakness_findings'), function (Blueprint $table): void {
            $table->dropColumn(['first_detected_scan_id', 'last_detected_scan_id', 'evidence_hash']);
        });
        Schema::dropIfExists(TableName::resolve('capell_security_scan_runs'));
    }
};
