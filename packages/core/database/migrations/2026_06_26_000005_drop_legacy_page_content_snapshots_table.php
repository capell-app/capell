<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Forward cleanup for installs that ran the retired page content-snapshot
 * subsystem. That feature's create migration was deleted when event-sourcing
 * replaced it, so its down() can no longer run — existing databases keep a dead
 * `page_content_snapshots` table. Drop it explicitly here. Fresh installs that
 * never created the table are unaffected (dropIfExists is a no-op).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('page_content_snapshots');
    }

    public function down(): void
    {
        // Irreversible: the snapshot subsystem is retired and its schema is no
        // longer defined anywhere. There is nothing to recreate.
    }
};
