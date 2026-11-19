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
        $table = TableName::resolve('capell_host_load_rollups');

        if (Schema::hasTable($table)) {
            return;
        }

        // One row for each server and hour. A server is not a site: nothing here is site content or a person's data.
        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('node', 191);
            $table->unsignedBigInteger('hour_start');
            $table->unsignedInteger('cores')->default(1);
            $table->unsignedInteger('samples')->default(0);
            $table->double('load5_sum')->default(0);
            $table->double('load5_peak')->default(0);
            $table->double('memory_available_min_percent')->nullable();
            $table->double('swap_used_peak_percent')->nullable();
            $table->unsignedInteger('queue_depth_peak')->nullable();
            // Up to three command names with the longest scheduled run seen in the hour.
            $table->json('busiest_tasks')->nullable();
            // Newest reading already counted, so rolling the same readings up twice adds nothing.
            $table->unsignedBigInteger('last_sample_at')->default(0);
            $table->timestamps();

            $table->unique(['node', 'hour_start'], TableName::namedIndex('capell_host_load_rollups', 'capell_host_load_rollups_node_hour_unique'));
            $table->index('hour_start', TableName::namedIndex('capell_host_load_rollups', 'capell_host_load_rollups_hour_start_index'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(TableName::resolve('capell_host_load_rollups'));
    }
};
