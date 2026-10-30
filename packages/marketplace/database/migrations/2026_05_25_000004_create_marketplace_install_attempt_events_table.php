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
        if (! TableName::existsAndIsOwned('marketplace_install_attempt_events', basename(__FILE__, '.php'))) {
            Schema::create(TableName::resolve('marketplace_install_attempt_events'), function (Blueprint $table): void {
                $table->id();
                $table
                    ->foreignId('marketplace_install_attempt_id')
                    ->constrained(TableName::resolve('marketplace_install_attempts'), indexName: TableName::namedIndex('marketplace_install_attempt_events', 'mkp_install_events_attempt_fk'))
                    ->cascadeOnDelete();
                $table->string('level')->index(TableName::index('marketplace_install_attempt_events', 'level', 'index'));
                $table->string('stage')->nullable()->index(TableName::index('marketplace_install_attempt_events', 'stage', 'index'));
                $table->string('message');
                $table->json('context')->nullable();
                $table->text('output_excerpt')->nullable();
                $table->timestamp('occurred_at')->index(TableName::index('marketplace_install_attempt_events', 'occurred_at', 'index'));
                $table->timestamps();

                $table->index(['marketplace_install_attempt_id', 'occurred_at'], TableName::namedIndex('marketplace_install_attempt_events', 'mkp_install_events_attempt_occurred_idx'));
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('marketplace_install_attempt_events'));
    }
};
