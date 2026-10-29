<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

it('identifies literal first-party table references with their file and line', function (): void {
    expect(capellTableReferenceViolations("<?php\nDB::table('pages');\n", 'fixture.php', ['pages']))
        ->toBe(['fixture.php:2: pages']);
});

/** @param list<string> $tableNames @return list<string> */
function capellTableReferenceViolations(string $source, string $path, array $tableNames): array
{
    $names = implode('|', array_map(static fn (string $name): string => preg_quote($name, '~'), $tableNames));
    $pattern = "~(?:Schema::(?:create|table|drop|dropIfExists|hasTable|hasColumn|hasColumns|hasIndex|rename)|DB::table|Rule::(?:exists|unique)|->(?:table|join|leftJoin|from|constrained|on))\\s*\\(\\s*['\"](" . $names . ")(?:\\s+as\\s+[a-zA-Z_][a-zA-Z_0-9]*)?[\"']|['\"](?:exists|unique):(" . $names . ")(?:,|['\"])~";
    preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    $violations = [];
    foreach ($matches[0] as $index => [, $offset]) {
        $name = $matches[1][$index][0] !== '' ? $matches[1][$index][0] : $matches[2][$index][0];
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        $violations[] = $path . ':' . $line . ': ' . $name;
    }

    preg_match_all("~Schema::rename\\([^,]+,\\s*['\"](" . $names . ")[\"']~", $source, $renameMatches, PREG_OFFSET_CAPTURE);
    foreach ($renameMatches[0] as $index => [, $offset]) {
        $line = substr_count(substr($source, 0, $offset), "\n") + 1;
        $violations[] = $path . ':' . $line . ': ' . $renameMatches[1][$index][0];
    }

    if ((str_contains($path, '/Models/') || str_ends_with($path, 'model.php')) && ! str_contains($source, 'use HasCapellTable;') && ! str_contains($source, 'use \\Capell\\Core\\Concerns\\HasCapellTable;')) {
        preg_match_all("~(?:protected|public)\\s+(?:string\\s+)?\\\$table\\s*=\\s*['\"](" . $names . ")[\"']~", $source, $modelMatches, PREG_OFFSET_CAPTURE);
        foreach ($modelMatches[0] as $index => [, $offset]) {
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $violations[] = $path . ':' . $line . ': ' . $modelMatches[1][$index][0];
        }
    }

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $nodes = $parser->parse($source) ?? [];
    $finder = new NodeFinder;
    $calls = $finder->find($nodes, static function (Node $node): bool {
        if ($node instanceof MethodCall && $node->name instanceof Identifier) {
            return in_array($node->name->toString(), ['whereColumn', 'selectRaw', 'whereRaw', 'orderByRaw', 'on', 'groupBy', 'pluck', 'value'], true);
        }

        return $node instanceof StaticCall && $node->class instanceof Name
            && in_array($node->class->toString(), ['DB', DB::class], true) && $node->name instanceof Identifier && $node->name->toString() === 'raw';
    });
    foreach ($calls as $call) {
        foreach ($call->args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $values = $arg->value instanceof String_
                ? [$arg->value]
                : ($arg->value instanceof Array_ ? $finder->findInstanceOf($arg->value, String_::class) : []);
            foreach ($values as $value) {
                if (preg_match('~\\b(' . $names . ')\\.[a-zA-Z_*][a-zA-Z_0-9*]*~', $value->value, $qualified) === 1) {
                    $violations[] = $path . ':' . $value->getStartLine() . ': ' . $qualified[1];
                }
            }
        }
    }

    return $violations;
}

it('routes first-party table references through the resolver', function (): void {
    $tables = [
        'activity_buckets', 'activity_visitors', 'admin_upload_grants', 'asset_attachments', 'asset_relations',
        'block_templates', 'blueprint_property_sets', 'blueprint_schema_snapshots', 'blueprints',
        'capell_admin_notification_subscriptions', 'capell_extension_health_alerts', 'capell_extensions',
        'capell_marketplace_installs', 'capell_public_render_contract_events', 'capell_reporting_incidents',
        'capell_upgrade_locks', 'capell_upgrade_log', 'capell_upgrade_run_events', 'capell_upgrade_runs',
        'content_graph_edges', 'content_locks', 'deletion_batch_records', 'deletion_batches', 'editor_scratch_drafts',
        'languages', 'layout_content_snapshots', 'layouts', 'marketplace_account_connection_sessions',
        'marketplace_install_attempt_events', 'marketplace_install_attempts', 'marketplace_install_flow_sessions',
        'marketplace_install_intents', 'marketplace_instances', 'marketplace_update_advisory_snapshots',
        'marketplace_update_notice_dismissals', 'metric_collection_runs', 'metric_daily_rollups', 'metric_events',
        'page_content_snapshots', 'page_property_values', 'page_revisions', 'page_role_restrictions', 'page_term', 'page_urls',
        'page_workflow_states', 'pages', 'property_definitions', 'property_sets', 'redirect_health_snapshots',
        'site_domain_claim_locks', 'site_domains', 'sites', 'taxonomies', 'term_property_values', 'terms',
        'themes', 'translations',
    ];
    // New literal create calls must not evade the audit merely because the
    // table has not yet been added to the established inventory.
    foreach (['core', 'admin', 'frontend', 'installer', 'marketplace'] as $package) {
        $directory = dirname(__DIR__, 4) . '/packages/' . $package . '/database/migrations';
        foreach (glob($directory . '/*.php') ?: [] as $migration) {
            $source = file_get_contents($migration);
            throw_if($source === false, RuntimeException::class, 'Cannot read ' . $migration);

            preg_match_all("~Schema::create\\(\\s*['\"]([a-z_][a-z_0-9]*)['\"]~", $source, $literalCreates);
            foreach ($literalCreates[1] as $table) {
                if (! in_array($table, ['users', 'media', 'settings', 'roles', 'permissions', 'tags', 'taggables', 'stored_events', 'snapshots'], true)) {
                    $tables[] = $table;
                }
            }
        }
    }

    $tables = array_values(array_unique($tables));
    $violations = [];
    $checkedFiles = 0;
    foreach (['core', 'admin', 'frontend', 'installer', 'marketplace'] as $package) {
        foreach (['src', 'database/migrations'] as $directory) {
            $root = dirname(__DIR__, 4) . '/packages/' . $package . '/' . $directory;
            if (! is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $checkedFiles++;
                $path = $file->getPathname();
                $source = file_get_contents($path);
                throw_if($source === false, RuntimeException::class, 'Cannot read ' . $path);

                array_push($violations, ...capellTableReferenceViolations($source, $path, $tables));
            }
        }
    }

    expect($checkedFiles)->toBeGreaterThan(100)
        ->and($violations)->toBe([]);
});

it('identifies literal joins and model tables that bypass the resolver', function (): void {
    expect(capellTableReferenceViolations("<?php\n\$query->join('pages', 'pages.id', '=', 'x.id');\n", 'join.php', ['pages']))
        ->toBe(['join.php:2: pages'])
        ->and(capellTableReferenceViolations("<?php\nclass Example { protected \$table = 'pages'; }\n", 'model.php', ['pages']))
        ->toBe(['model.php:2: pages']);
});

it('identifies aliased literal query tables', function (): void {
    expect(capellTableReferenceViolations("<?php\n\$query->from('pages as p');\n", 'alias.php', ['pages']))
        ->toBe(['alias.php:2: pages']);
});

it('identifies literal foreign-key targets and the second rename table', function (): void {
    expect(capellTableReferenceViolations("<?php\n\$table->foreignId('site_id')->constrained('sites');\n", 'foreign.php', ['sites']))
        ->toBe(['foreign.php:2: sites'])
        ->and(capellTableReferenceViolations("<?php\nSchema::rename(TableName::resolve('pages'), 'sites');\n", 'rename.php', ['pages', 'sites']))
        ->toBe(['rename.php:2: sites']);
});

it('identifies qualified table literals in query expressions', function (string $query): void {
    expect(capellTableReferenceViolations("<?php\n" . $query . "\n", 'query.php', ['pages']))
        ->toBe(['query.php:2: pages']);
})->with([
    "\$q->whereColumn('pages.id', 'x.id');",
    "\$q->selectRaw('MAX(pages.id) as id');",
    "\$q->whereRaw('pages.id > 1');",
    "\$q->orderByRaw('pages.id desc');",
    "\$q->on('pages.id', '=', 'x.id');",
    "\$q->groupBy('pages.id');",
    "\$q->pluck('pages.id');",
    "\$q->value('pages.id');",
    "DB::raw('MAX(pages.id)');",
]);

it('reports the literal line for a multiline qualified query', function (): void {
    expect(capellTableReferenceViolations("<?php\n\$query->whereColumn(\n    'pages.id', 'other.id'\n);\n", 'query.php', ['pages']))
        ->toBe(['query.php:3: pages']);
});

it('identifies qualified table literals in a fully qualified DB raw expression', function (): void {
    expect(capellTableReferenceViolations('<?php
' . DB::class . '::raw(\'MAX(pages.id)\');
', 'query.php', ['pages']))
        ->toBe(['query.php:2: pages']);
});
