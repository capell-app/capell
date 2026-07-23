<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capell_upgrade_locks', function (Blueprint $table): void {
            $table->id();

            // The unique index is the mutual exclusion. Two nodes racing to start an
            // upgrade both insert this row; the database lets exactly one succeed,
            // regardless of what cache store each node happens to be using.
            $table->string('name')->unique();

            // Identifies the holder, so a release cannot free somebody else's lock
            // after a takeover.
            $table->string('token', 64);

            $table->string('owner')->nullable();
            $table->dateTime('acquired_at');

            // A hard-killed process cannot release its lock, so the row carries its
            // own expiry rather than stranding upgrades until someone intervenes.
            $table->dateTime('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capell_upgrade_locks');
    }
};
