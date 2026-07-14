<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics;

use Illuminate\Support\Facades\Schema;

final class CapellRuntimeSchemaContract
{
    /** @return list<string> */
    public function footprintAnchors(): array
    {
        return ['sites', 'pages', 'capell_extensions'];
    }

    /** @return list<string> */
    public function coreTables(): array
    {
        return [
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
        ];
    }

    /** @return list<string> */
    public function themeAndLayoutTables(): array
    {
        return ['themes', 'layouts'];
    }

    /** @return list<string> */
    public function eventSourcingTables(): array
    {
        return ['stored_events', 'snapshots'];
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
        if ($existingTables !== null) {
            return array_intersect($this->footprintAnchors(), $existingTables) !== [];
        }

        return collect($this->footprintAnchors())->contains(
            static fn (string $table): bool => Schema::hasTable($table),
        );
    }
}
