<?php

declare(strict_types=1);

use Capell\Core\Contracts\Database\RepairsImplicitTimestampUpdates;
use Capell\Core\Facades\CapellDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, string> */
    private const array COLUMNS = [
        'capell_upgrade_log' => 'ran_at',
        'capell_upgrade_run_events' => 'occurred_at',
        'content_locks' => 'expires_at',
        'layout_content_snapshots' => 'taken_at',
        'blueprint_schema_snapshots' => 'taken_at',
        'stored_events' => 'created_at',
        'page_revisions' => 'occurred_at',
        'metric_collection_runs' => 'started_at',
        'activity_buckets' => 'bucket_started_at',
        'editor_scratch_drafts' => 'saved_at',
        'activity_visitors' => 'first_seen_at',
    ];

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $dialect = CapellDatabase::for($connection)->schemaDialect();

        if (! $dialect instanceof RepairsImplicitTimestampUpdates) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            $dialect->dropImplicitTimestampUpdate($table, $column, $connection);
        }
    }

    public function down(): void
    {
        // Reintroducing automatic event/expiry rewrites would corrupt existing data.
    }
};
