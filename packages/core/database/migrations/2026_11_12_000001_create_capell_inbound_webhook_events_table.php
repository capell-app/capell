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
        Schema::create(TableName::resolve('capell_inbound_webhook_events'), function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 120);
            $table->string('event_id', 191);
            $table->string('type', 191);
            $table->string('subject', 191)->nullable();
            $table->dateTime('occurred_at');
            $table->string('status', 20);
            // Encrypted: provider payloads can carry personal data.
            $table->longText('payload');
            $table->char('payload_hash', 64);
            $table->string('handler')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], TableName::namedIndex('capell_inbound_webhook_events', 'capell_inbound_webhook_events_provider_event_unique'));
            $table->index(['provider', 'subject', 'occurred_at'], TableName::namedIndex('capell_inbound_webhook_events', 'capell_inbound_webhook_events_subject_index'));
            $table->index(['status', 'created_at'], TableName::namedIndex('capell_inbound_webhook_events', 'capell_inbound_webhook_events_status_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_inbound_webhook_events'));
    }
};
