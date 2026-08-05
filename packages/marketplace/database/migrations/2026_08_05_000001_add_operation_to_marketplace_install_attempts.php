<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teach the attempts table what kind of operation it is recording.
 *
 * Both columns are added together and both are additive. `operation` defaults
 * to 'install' so every row written before queued updates existed keeps its
 * meaning, and `uninstall_options` is nullable because only one of the three
 * operations ever populates it.
 *
 * The column-existence check is per column rather than for the pair, so this
 * migration is safe to run against a database where a sibling change already
 * added one of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            if (! Schema::hasColumn('marketplace_install_attempts', 'operation')) {
                $table->string('operation')->default('install')->index();
            }

            if (! Schema::hasColumn('marketplace_install_attempts', 'uninstall_options')) {
                $table->json('uninstall_options')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_install_attempts', function (Blueprint $table): void {
            if (Schema::hasColumn('marketplace_install_attempts', 'operation')) {
                $table->dropIndex(['operation']);
                $table->dropColumn('operation');
            }

            if (Schema::hasColumn('marketplace_install_attempts', 'uninstall_options')) {
                $table->dropColumn('uninstall_options');
            }
        });
    }
};
