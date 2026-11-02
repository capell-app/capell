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
        Schema::create(TableName::resolve('capell_security_weakness_findings'), function (Blueprint $table): void {
            $table->id();
            $table->string('weakness_id', 120);
            $table->string('fingerprint', 64)->unique(TableName::index('capell_security_weakness_findings', 'fingerprint', 'unique'));
            $table->string('source', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('title');
            $table->text('summary');
            $table->json('evidence')->nullable();
            $table->string('composer_name')->nullable();
            $table->boolean('remediable')->default(false);
            $table->text('recommended_action')->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at', 6);
            $table->timestamp('remediated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedBigInteger('dismissed_by')->nullable();
            $table->text('dismissal_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'severity'], TableName::index('capell_security_weakness_findings', 'status_severity', 'index'));
            $table->index('weakness_id', TableName::index('capell_security_weakness_findings', 'weakness_id', 'index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_security_weakness_findings'));
    }
};
