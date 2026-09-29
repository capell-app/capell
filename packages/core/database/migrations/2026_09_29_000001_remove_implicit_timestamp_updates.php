<?php

declare(strict_types=1);

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
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();
        foreach (self::COLUMNS as $table => $column) {
            /** @var object{COLUMN_TYPE: string, EXTRA: string}|null $metadata */
            $metadata = $connection->selectOne(
                'SELECT COLUMN_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$connection->getDatabaseName(), $connection->getTablePrefix() . $table, $column],
            );
            if ($metadata === null) {
                continue;
            }

            if (! str_contains(strtolower($metadata->EXTRA), 'on update current_timestamp')) {
                continue;
            }

            if (preg_match('/^timestamp(?:\(([0-6])\))?$/i', $metadata->COLUMN_TYPE, $type) !== 1) {
                continue;
            }

            $precision = isset($type[1]) ? '(' . $type[1] . ')' : '';
            // Legacy MariaDB adds ON UPDATE to the first required TIMESTAMP.
            // An explicit default preserves insert behaviour without rewriting history.
            $connection->statement(sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s TIMESTAMP%s NOT NULL DEFAULT CURRENT_TIMESTAMP%s',
                $grammar->wrapTable($table),
                $grammar->wrap($column),
                $precision,
                $precision,
            ));
        }
    }

    public function down(): void
    {
        // Reintroducing automatic event/expiry rewrites would corrupt existing data.
    }
};
