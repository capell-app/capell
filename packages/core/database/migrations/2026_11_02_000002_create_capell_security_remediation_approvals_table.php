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
        Schema::create(TableName::resolve('capell_security_remediation_approvals'), function (Blueprint $table): void {
            $table->id();
            $table->string('weakness_id', 120)->unique(TableName::index('capell_security_remediation_approvals', 'weakness_id', 'unique'));
            $table->string('remediation_class', 255)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_security_remediation_approvals'));
    }
};
