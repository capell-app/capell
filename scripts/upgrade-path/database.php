<?php

declare(strict_types=1);

/**
 * Database evidence for scripts/upgrade-path.sh: what a SQLite site held before an upgrade,
 * and whether all of it is still there afterwards.
 *
 * Usage:
 *   database.php snapshot <database.sqlite> <snapshot.json>
 *   database.php compare <before.json> <after.json> <allowed-changes.json>
 *   database.php pick-column <snapshot.json> <allowed-changes.json>
 *   database.php page-paths <database.sqlite>
 *
 * A snapshot records, for every table, its row count and for every column how many rows hold a
 * value. `compare` fails when a table or column has gone, or when a table has fewer rows or a
 * column fewer values than before, unless allowed-changes.json names it with a reason.
 * `pick-column` names a populated column that a migration can drop, for the seeded
 * destructive-migration fault. `page-paths` lists the addresses the site's pages are published
 * at, so pages no menu links to are checked too.
 */

/**
 * @return array<string, array{rows: int, columns: array<string, array{values: int, droppable: bool}>}>
 */
function snapshot(string $databasePath): array
{
    if (! is_file($databasePath)) {
        throw new RuntimeException(sprintf('No SQLite database at %s.', $databasePath));
    }

    $pdo = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    $snapshot = [];

    foreach ($tables as $table) {
        $quotedTable = quoteIdentifier((string) $table);
        $constrained = constrainedColumns($pdo, $quotedTable);
        $columns = [];

        foreach ($pdo->query(sprintf('PRAGMA table_info(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $name = (string) $column['name'];
            $columns[$name] = [
                'values' => (int) $pdo->query(sprintf('SELECT COUNT(%s) FROM %s', quoteIdentifier($name), $quotedTable))->fetchColumn(),
                'droppable' => (int) $column['pk'] === 0 && ! in_array($name, $constrained, true),
            ];
        }

        $snapshot[(string) $table] = [
            'rows' => (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $quotedTable))->fetchColumn(),
            'columns' => $columns,
        ];
    }

    return $snapshot;
}

/**
 * Columns SQLite refuses to drop: those in an index or a foreign key.
 *
 * @return list<string>
 */
function constrainedColumns(PDO $pdo, string $quotedTable): array
{
    $columns = [];

    foreach ($pdo->query(sprintf('PRAGMA index_list(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $index) {
        foreach ($pdo->query(sprintf('PRAGMA index_info(%s)', quoteIdentifier((string) $index['name'])))->fetchAll(PDO::FETCH_ASSOC) as $indexed) {
            $columns[] = (string) $indexed['name'];
        }
    }

    foreach ($pdo->query(sprintf('PRAGMA foreign_key_list(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $foreignKey) {
        $columns[] = (string) $foreignKey['from'];
    }

    return array_values(array_unique($columns));
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

/**
 * @param  array<string, array{rows: int, columns: array<string, array{values: int, droppable: bool}>}>  $before
 * @param  array<string, array{rows: int, columns: array<string, array{values: int, droppable: bool}>}>  $after
 * @param  array{tables: array<string, string>, columns: array<string, string>}  $allowed
 * @return list<string>
 */
function losses(array $before, array $after, array $allowed): array
{
    $losses = [];

    foreach ($before as $table => $tableBefore) {
        if (array_key_exists($table, $allowed['tables'])) {
            continue;
        }

        if (! array_key_exists($table, $after)) {
            $losses[] = sprintf('table %s has gone (%d rows before the upgrade)', $table, $tableBefore['rows']);

            continue;
        }

        if ($after[$table]['rows'] < $tableBefore['rows']) {
            $losses[] = sprintf('table %s lost rows: %d before, %d after', $table, $tableBefore['rows'], $after[$table]['rows']);
        }

        foreach ($tableBefore['columns'] as $column => $columnBefore) {
            if (array_key_exists($table . '.' . $column, $allowed['columns'])) {
                continue;
            }

            if (! array_key_exists($column, $after[$table]['columns'])) {
                $losses[] = sprintf('column %s.%s has gone (%d values before the upgrade)', $table, $column, $columnBefore['values']);

                continue;
            }

            $valuesAfter = $after[$table]['columns'][$column]['values'];

            if ($valuesAfter < $columnBefore['values']) {
                $losses[] = sprintf('column %s.%s lost values: %d before, %d after', $table, $column, $columnBefore['values'], $valuesAfter);
            }
        }
    }

    return $losses;
}

/**
 * @param  array<string, array{rows: int, columns: array<string, array{values: int, droppable: bool}>}>  $snapshot
 * @param  array{tables: array<string, string>, columns: array<string, string>}  $allowed
 * @return array{string, string}|null
 */
function pickColumn(array $snapshot, array $allowed): ?array
{
    $candidates = [];

    foreach ($snapshot as $table => $details) {
        if (array_key_exists($table, $allowed['tables'])) {
            continue;
        }

        foreach ($details['columns'] as $column => $columnDetails) {
            $guarded = array_key_exists($table . '.' . $column, $allowed['columns']);

            if ($columnDetails['droppable'] && $columnDetails['values'] > 0 && ! $guarded) {
                $candidates[] = [$table, $column];
            }
        }
    }

    // Page content first, so the seeded fault removes something an editor wrote.
    $rank = static fn (array $candidate): array => [$candidate[0] !== 'pages', ! str_contains($candidate[0], 'page'), $candidate];
    usort($candidates, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

    return $candidates[0] ?? null;
}

/**
 * @return array<string, mixed>
 */
function readJson(string $path): array
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('Cannot read %s.', $path));
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException(sprintf('%s does not hold a JSON object.', $path));
    }

    return $decoded;
}

/**
 * @return array{tables: array<string, string>, columns: array<string, string>}
 */
function readAllowedChanges(string $path): array
{
    $allowed = readJson($path);

    return [
        'tables' => is_array($allowed['tables'] ?? null) ? $allowed['tables'] : [],
        'columns' => is_array($allowed['columns'] ?? null) ? $allowed['columns'] : [],
    ];
}

/**
 * @param  list<string>  $arguments
 */
function main(array $arguments): int
{
    $command = $arguments[1] ?? '';

    if ($command === 'snapshot' && count($arguments) === 4) {
        $snapshot = snapshot($arguments[2]);
        file_put_contents($arguments[3], json_encode($snapshot, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
        printf("%d tables, %d rows\n", count($snapshot), array_sum(array_column($snapshot, 'rows')));

        return 0;
    }

    if ($command === 'compare' && count($arguments) === 5) {
        $losses = losses(readJson($arguments[2]), readJson($arguments[3]), readAllowedChanges($arguments[4]));

        foreach ($losses as $loss) {
            printf("LOST: %s\n", $loss);
        }

        if ($losses !== []) {
            printf("%d loss(es). An intended removal is declared, with its reason, in %s.\n", count($losses), $arguments[4]);

            return 1;
        }

        echo "Every table, column, row and value from before the upgrade is still present.\n";

        return 0;
    }

    if ($command === 'pick-column' && count($arguments) === 4) {
        $picked = pickColumn(readJson($arguments[2]), readAllowedChanges($arguments[3]));

        if ($picked === null) {
            fwrite(STDERR, "The snapshot has no populated column that a migration could drop.\n");

            return 1;
        }

        echo $picked[0] . ' ' . $picked[1] . PHP_EOL;

        return 0;
    }

    if ($command === 'page-paths' && count($arguments) === 3) {
        $pdo = new PDO('sqlite:' . $arguments[2], options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $paths = $pdo->query("SELECT DISTINCT url FROM page_urls WHERE url LIKE '/%' AND deleted_at IS NULL ORDER BY url")
            ->fetchAll(PDO::FETCH_COLUMN);
        echo implode(PHP_EOL, $paths), PHP_EOL;

        return 0;
    }

    fwrite(STDERR, "Usage: database.php snapshot|compare|pick-column|page-paths ... (see the file header)\n");

    return 2;
}

exit(main($argv));
