<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
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
        // Newly named connections initialise their schema grammar lazily.
        $connection->getSchemaBuilder();
        if (! $connection->getSchemaGrammar() instanceof MySqlGrammar) {
            return;
        }

        foreach (self::COLUMNS as $table => $column) {
            $this->dropImplicitTimestampUpdate($table, $column, $connection);
        }
    }

    public function down(): void
    {
        // Reintroducing automatic event/expiry rewrites would corrupt existing data.
    }

    private function dropImplicitTimestampUpdate(string $table, string $column, Connection $connection): void
    {
        // Laravel's processed getColumns() metadata omits automatic updates.
        // Use its schema grammar to retain that field without embedding dialect SQL.
        /** @var list<object{name: string, type: string, nullable: string, default: ?string, comment: string, extra: string}> $columns */
        $columns = $connection->selectFromWriteConnection(
            $connection->getSchemaGrammar()->compileColumns(
                $connection->getDatabaseName(),
                $connection->getTablePrefix() . $table,
            ),
        );

        foreach ($columns as $metadata) {
            if ($metadata->name !== $column) {
                continue;
            }

            if (! str_contains(strtolower($metadata->extra), 'on update current_timestamp')) {
                continue;
            }

            if (preg_match('/^timestamp(?:\(([0-6])\))?$/i', $metadata->type, $type) !== 1) {
                continue;
            }

            $default = $this->timestampDefault($metadata->default, $table, $column);
            $precision = isset($type[1]) ? (int) $type[1] : 0;

            // Keep the insert default explicit so legacy servers cannot restore
            // automatic updates when explicit timestamp defaults are disabled.
            $connection->getSchemaBuilder()->table($table, function (Blueprint $blueprint) use ($column, $metadata, $precision, $default): void {
                $blueprint->timestamp($column, $precision)
                    ->nullable($metadata->nullable === 'YES')
                    ->default($default)
                    ->comment($metadata->comment)
                    ->change();
            });
        }
    }

    /** @return Expression<covariant literal-string>|string */
    private function timestampDefault(?string $default, string $table, string $column): Expression|string
    {
        // Catalogue defaults may represent SQL NULL as an unquoted string.
        if ($default === null || strtoupper($default) === 'NULL') {
            return new Expression('NULL');
        }

        $current = match (strtolower($default)) {
            'current_timestamp', 'current_timestamp()' => 'CURRENT_TIMESTAMP',
            'current_timestamp(0)' => 'CURRENT_TIMESTAMP(0)',
            'current_timestamp(1)' => 'CURRENT_TIMESTAMP(1)',
            'current_timestamp(2)' => 'CURRENT_TIMESTAMP(2)',
            'current_timestamp(3)' => 'CURRENT_TIMESTAMP(3)',
            'current_timestamp(4)' => 'CURRENT_TIMESTAMP(4)',
            'current_timestamp(5)' => 'CURRENT_TIMESTAMP(5)',
            'current_timestamp(6)' => 'CURRENT_TIMESTAMP(6)',
            default => null,
        };

        if ($current !== null) {
            return new Expression($current);
        }

        if (str_starts_with($default, "'") && str_ends_with($default, "'")) {
            $default = str_replace("''", "'", substr($default, 1, -1));
        }

        // Expressions must never become quoted literals during a historical repair.
        throw_unless(
            preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)?$/', $default) === 1,
            RuntimeException::class,
            sprintf('Cannot remove implicit timestamp updates from [%s.%s]: unsupported TIMESTAMP default [%s].', $table, $column, $default),
        );

        return $default;
    }
};
