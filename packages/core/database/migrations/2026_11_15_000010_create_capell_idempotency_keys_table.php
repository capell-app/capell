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
        Schema::create(TableName::resolve('capell_idempotency_keys'), function (Blueprint $table): void {
            $table->id();
            $table->string('key', 191);
            $table->char('payload_hash', 64);
            $table->string('status', 20);
            // Encrypted: a replayed result can carry personal or provider data.
            $table->longText('result')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique('key', TableName::namedIndex('capell_idempotency_keys', 'capell_idempotency_keys_key_unique'));
            $table->index('expires_at', TableName::namedIndex('capell_idempotency_keys', 'capell_idempotency_keys_expires_at_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_idempotency_keys'));
    }
};
