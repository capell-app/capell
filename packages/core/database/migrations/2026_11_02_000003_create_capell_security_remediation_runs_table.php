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
        Schema::create(TableName::resolve('capell_security_remediation_runs'), function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finding_id')
                ->nullable()
                ->constrained(TableName::resolve('capell_security_weakness_findings'), indexName: TableName::index('capell_security_remediation_runs', 'finding_id', 'foreign'))
                ->nullOnDelete();
            $table->string('weakness_id', 120);
            $table->string('remediation_class');
            $table->string('actor', 120);
            $table->string('outcome', 20);
            $table->text('message');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('weakness_id', TableName::index('capell_security_remediation_runs', 'weakness_id', 'index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_security_remediation_runs'));
    }
};
