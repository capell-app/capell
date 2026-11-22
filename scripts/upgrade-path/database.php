<?php

declare(strict_types=1);

/**
 * Database evidence for scripts/upgrade-path.sh: what a site's database held before an upgrade,
 * and whether all of it is still there afterwards. Works on SQLite and on MySQL or MariaDB.
 *
 * Usage:
 *   database.php snapshot <dsn> <snapshot.json>
 *   database.php compare <before.json> <after.json> <allowed-changes.json>
 *   database.php pick-column <snapshot.json> <allowed-changes.json>
 *   database.php page-paths <dsn>
 *   database.php ready <dsn>
 *
 * <dsn> is a PDO data source name, for example sqlite:/path/database.sqlite or
 * mysql:host=127.0.0.1;port=3306;dbname=capell. A server's user and password are read from the
 * UPGRADE_PATH_DB_USER and UPGRADE_PATH_DB_PASSWORD environment variables.
 *
 * A snapshot records, for every table, its row count and for every column how many rows hold a
 * value. `compare` fails when a table or column has gone, or when a table has fewer rows or a
 * column fewer values than before, unless allowed-changes.json names it with a reason.
 * `pick-column` names a populated column that a migration can drop, for the seeded
 * destructive-migration fault. `page-paths` lists the addresses the site's pages are published
 * at, so pages no menu links to are checked too. `ready` exits 0 once the server accepts a
 * connection.
 */
function connect(string $dsn): PDO
{
    if (str_starts_with($dsn, 'sqlite:') && ! is_file(substr($dsn, 7))) {
        throw new RuntimeException(sprintf('No SQLite database at %s.', substr($dsn, 7)));
    }

    $user = getenv('UPGRADE_PATH_DB_USER');
    $password = getenv('UPGRADE_PATH_DB_PASSWORD');

    return new PDO($dsn, $user === false ? null : $user, $password === false ? null : $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function isSqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/**
 * @return array<string, array{rows: int, columns: array<string, array{values: int, droppable: bool}>}>
 */
function snapshot(PDO $pdo): array
{
    $snapshot = [];

    foreach (tableNames($pdo) as $table) {
        $quotedTable = quoteIdentifier($pdo, $table);
        $columns = [];

        foreach (columnsOf($pdo, $table) as $name => $droppable) {
            $columns[$name] = [
                'values' => (int) $pdo->query(sprintf('SELECT COUNT(%s) FROM %s', quoteIdentifier($pdo, $name), $quotedTable))->fetchColumn(),
                'droppable' => $droppable,
            ];
        }

        $snapshot[$table] = [
            'rows' => (int) $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $quotedTable))->fetchColumn(),
            'columns' => $columns,
        ];
    }

    return $snapshot;
}

/**
 * @return list<string>
 */
function tableNames(PDO $pdo): array
{
    $sql = isSqlite($pdo)
        ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        : "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name";

    return array_map(strval(...), $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Each column of a table, and whether a migration can drop it: not a key, not indexed and not
 * part of a foreign key.
 *
 * @return array<string, bool>
 */
function columnsOf(PDO $pdo, string $table): array
{
    $columns = [];

    if (isSqlite($pdo)) {
        $quotedTable = quoteIdentifier($pdo, $table);
        $constrained = constrainedSqliteColumns($pdo, $quotedTable);

        foreach ($pdo->query(sprintf('PRAGMA table_info(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['name']] = (int) $column['pk'] === 0 && ! in_array($column['name'], $constrained, true);
        }

        return $columns;
    }

    $statement = $pdo->prepare(
        'SELECT c.column_name AS name, EXISTS (
            SELECT 1 FROM information_schema.statistics s
            WHERE s.table_schema = c.table_schema AND s.table_name = c.table_name AND s.column_name = c.column_name
        ) AS constrained
        FROM information_schema.columns c
        WHERE c.table_schema = DATABASE() AND c.table_name = ?
        ORDER BY c.ordinal_position',
    );
    $statement->execute([$table]);

    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
        // Every MySQL key, primary and foreign included, is backed by an index.
        $columns[(string) $column['name']] = (int) $column['constrained'] === 0;
    }

    return $columns;
}

/**
 * Columns SQLite refuses to drop: those in an index or a foreign key.
 *
 * @return list<string>
 */
function constrainedSqliteColumns(PDO $pdo, string $quotedTable): array
{
    $columns = [];

    foreach ($pdo->query(sprintf('PRAGMA index_list(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $index) {
        foreach ($pdo->query(sprintf('PRAGMA index_info(%s)', quoteIdentifier($pdo, (string) $index['name'])))->fetchAll(PDO::FETCH_ASSOC) as $indexed) {
            $columns[] = (string) $indexed['name'];
        }
    }

    foreach ($pdo->query(sprintf('PRAGMA foreign_key_list(%s)', $quotedTable))->fetchAll(PDO::FETCH_ASSOC) as $foreignKey) {
        $columns[] = (string) $foreignKey['from'];
    }

    return array_values(array_unique($columns));
}

function quoteIdentifier(PDO $pdo, string $identifier): string
{
    return isSqlite($pdo)
        ? '"' . str_replace('"', '""', $identifier) . '"'
        : '`' . str_replace('`', '``', $identifier) . '`';
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
        $snapshot = snapshot(connect($arguments[2]));
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
        $paths = connect($arguments[2])
            ->query("SELECT DISTINCT url FROM page_urls WHERE url LIKE '/%' AND deleted_at IS NULL ORDER BY url")
            ->fetchAll(PDO::FETCH_COLUMN);
        echo implode(PHP_EOL, $paths), PHP_EOL;

        return 0;
    }

    if ($command === 'ready' && count($arguments) === 3) {
        try {
            connect($arguments[2])->query('SELECT 1');
        } catch (PDOException) {
            return 1;
        }

        return 0;
    }

    fwrite(STDERR, "Usage: database.php snapshot|compare|pick-column|page-paths|ready ... (see the file header)\n");

    return 2;
}

exit(main($argv));
