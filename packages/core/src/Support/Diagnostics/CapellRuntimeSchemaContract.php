<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics;

use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Schema;

final class CapellRuntimeSchemaContract
{
    /** @return list<string> */
    public function footprintAnchors(): array
    {
        return array_map(TableName::resolve(...), ['sites', 'pages', 'capell_extensions']);
    }

    /** @return list<string> */
    public function coreTables(): array
    {
        return array_map(TableName::resolve(...), [
            'languages',
            'blueprints',
            'sites',
            'site_domains',
            'translations',
            'pages',
            'page_urls',
            'asset_attachments',
            'content_graph_edges',
            'content_locks',
            'block_templates',
            'layout_content_snapshots',
            'blueprint_schema_snapshots',
            'capell_extensions',
            'page_workflow_states',
            'page_revisions',
        ]);
    }

    /** @return list<string> */
    public function themeAndLayoutTables(): array
    {
        return array_map(TableName::resolve(...), ['themes', 'layouts']);
    }

    /** @return list<string> */
    public function eventSourcingTables(): array
    {
        return [(string) config('capell.database.external_tables.stored_events', 'stored_events'), (string) config('capell.database.external_tables.snapshots', 'snapshots')];
    }

    /** @return list<string> */
    public function requiredTables(): array
    {
        return array_values(array_unique([
            ...$this->coreTables(),
            ...$this->themeAndLayoutTables(),
            ...$this->eventSourcingTables(),
        ]));
    }

    /**
     * @param  list<string>|null  $existingTables
     * @return list<string>
     */
    public function missingTables(?array $existingTables = null): array
    {
        if ($existingTables !== null) {
            return array_values(array_diff($this->requiredTables(), $existingTables));
        }

        return array_values(array_filter(
            $this->requiredTables(),
            static fn (string $table): bool => ! Schema::hasTable($table),
        ));
    }

    /** @param list<string>|null $existingTables */
    public function hasFootprint(?array $existingTables = null): bool
    {
        $anchors = [
            'sites' => '2026_05_10_190832_05_create_sites_table',
            'pages' => '2026_05_10_190832_12_create_pages_table',
            'capell_extensions' => '2026_05_10_190832_24_create_capell_extensions_table',
        ];
        foreach ($anchors as $base => $migration) {
            $table = TableName::resolve($base);
            $exists = $existingTables !== null ? in_array($table, $existingTables, true) : Schema::hasTable($table);
            if ($exists && TableName::migrationRecorded($migration)) {
                return true;
            }
        }

        return false;
    }
}
