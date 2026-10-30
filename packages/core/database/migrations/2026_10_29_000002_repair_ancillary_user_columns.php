<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $languagesTable = TableName::resolve('languages');
        throw_if($connection->getDriverName() === 'sqlite'
            && (int) $this->sqliteQuery($connection->getPdo(), 'PRAGMA defer_foreign_keys')->fetchColumn() !== 0, RuntimeException::class, 'Unsupported SQLite deferred-foreign-key mode.');

        $sqliteSchemaVersion = $connection->getDriverName() === 'sqlite'
            ? (int) $this->sqliteQuery($connection->getPdo(), 'PRAGMA main.schema_version')->fetchColumn()
            : null;

        if (! Schema::hasTable('users')) {
            return;
        }

        throw_unless(in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlite'], true), RuntimeException::class, 'Cannot verify the language foreign key schema on this database driver.');

        if ($connection->getDriverName() === 'sqlite') {
            $pdo = $connection->getPdo();
            $tempObjects = $this->sqliteQuery($pdo, "SELECT name FROM sqlite_temp_schema WHERE type IN ('table', 'view')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tempObjects as $name) {
                throw_if(strcasecmp((string) $name, $connection->getTablePrefix() . 'users') === 0 || strcasecmp((string) $name, $connection->getTablePrefix() . $languagesTable) === 0, RuntimeException::class, 'SQLite temporary schema shadows a required table.');
            }

            $target = $pdo->prepare("SELECT name, pk FROM pragma_table_info(?, 'main')");
            $target->execute([$connection->getTablePrefix() . $languagesTable]);
            $targetColumns = $target->fetchAll(PDO::FETCH_ASSOC);
            $primaryColumns = array_values(array_filter($targetColumns, static fn (array $column): bool => (int) $column['pk'] > 0));
            throw_if(count($primaryColumns) !== 1 || strcasecmp((string) $primaryColumns[0]['name'], 'id') !== 0, RuntimeException::class, 'SQLite languages.id must exist as the sole primary key before user repair.');
        }

        if ($connection->getDriverName() === 'pgsql') {
            foreach (Schema::getColumns('users') as $column) {
                $expected = strtolower($column['name']);
                if (in_array($expected, ['bio', 'dismissed_hints', 'preferred_admin_language_id'], true) && $column['name'] !== $expected) {
                    throw new RuntimeException(sprintf('PostgreSQL users.%s must use the exact lowercase column name.', $expected));
                }
            }
        }

        $hasLanguageColumn = Schema::hasColumn('users', 'preferred_admin_language_id');
        $languageColumn = 'preferred_admin_language_id';
        $hasExpectedForeignKey = false;
        if ($hasLanguageColumn) {
            $matches = array_values(array_filter(Schema::getColumns('users'), static fn (array $column): bool => strtolower($column['name']) === 'preferred_admin_language_id'));
            throw_if(count($matches) !== 1, RuntimeException::class, 'The users preferred admin language column is ambiguous.');

            $languageColumn = $matches[0]['name'];
            throw_if($matches[0]['generation'] !== null, RuntimeException::class, 'The users.preferred_admin_language_id column cannot be generated.');

            throw_unless($matches[0]['nullable'], RuntimeException::class, 'The users.preferred_admin_language_id column must be nullable for ON DELETE SET NULL.');

            foreach (Schema::getIndexes('users') as $index) {
                throw_if($index['primary'] && in_array(strtolower((string) $languageColumn), array_map(strtolower(...), $index['columns']), true), RuntimeException::class, 'The users.preferred_admin_language_id column cannot be part of the primary key.');
            }

            foreach (Schema::getForeignKeys('users') as $foreignKey) {
                if (! in_array(strtolower((string) $languageColumn), array_map(strtolower(...), $foreignKey['columns']), true)) {
                    continue;
                }

                $languageTable = $connection->getTablePrefix() . $languagesTable;
                $languageSchema = match ($connection->getDriverName()) {
                    'mysql', 'mariadb' => $connection->getDatabaseName(),
                    'sqlite' => 'main',
                    'pgsql' => $connection->selectOne(
                        'select n.nspname as schema_name from pg_class c join pg_namespace n on n.oid = c.relnamespace where c.oid = to_regclass(quote_ident(?))',
                        [$languageTable],
                    )?->schema_name,
                };

                throw_if(array_map(strtolower(...), $foreignKey['columns']) !== [strtolower((string) $languageColumn)]
                    || $foreignKey['foreign_schema'] !== $languageSchema
                    || $foreignKey['foreign_table'] !== $languageTable
                    || $foreignKey['foreign_columns'] !== ['id']
                    || strtolower($foreignKey['on_delete']) !== 'set null', RuntimeException::class, 'The users.preferred_admin_language_id foreign key is incompatible; expected languages.id with ON DELETE SET NULL.');

                $hasExpectedForeignKey = true;
            }
        }

        if ($connection->getDriverName() === 'sqlite' && $hasLanguageColumn && ! $hasExpectedForeignKey) {
            throw_if($connection->transactionLevel() !== 0, RuntimeException::class, 'SQLite user repair requires no active transaction.');

            $this->sqliteRebuildUsers($connection->getPdo(), $connection->getTablePrefix() . 'users', $connection->getTablePrefix() . $languagesTable, null, $sqliteSchemaVersion);

            return;
        }

        if ($connection->getDriverName() === 'sqlite') {
            throw_if($sqliteSchemaVersion === null, RuntimeException::class, 'SQLite schema version unavailable.');

            $writableColumns = array_values(array_filter(Schema::getColumns('users'), static fn (array $column): bool => $column['generation'] === null));
            throw_if($writableColumns === [], RuntimeException::class, 'SQLite user repair requires a writable column to obtain a lock.');

            $grammar = $connection->getSchemaGrammar();
            $lockSql = 'UPDATE ' . $grammar->wrapTable('users') . ' SET ' . $grammar->wrap($writableColumns[0]['name']) . ' = ' . $grammar->wrap($writableColumns[0]['name']) . ' WHERE 0';
            $this->sqliteDirectRepair($connection->getPdo(), $sqliteSchemaVersion, function () use ($connection, $hasLanguageColumn, $languagesTable): void {
                if (! Schema::hasColumn('users', 'bio')) {
                    Schema::table('users', fn (Blueprint $table) => $table->text('bio')->nullable());
                }

                if (! Schema::hasColumn('users', 'dismissed_hints')) {
                    Schema::table('users', fn (Blueprint $table) => $table->json('dismissed_hints')->nullable());
                }

                if (! $hasLanguageColumn) {
                    $grammar = $connection->getSchemaGrammar();
                    $connection->statement(sprintf(
                        'alter table %s add column %s integer references %s (%s) on delete set null',
                        $grammar->wrapTable('users'),
                        $grammar->wrap('preferred_admin_language_id'),
                        $grammar->wrapTable($languagesTable),
                        $grammar->wrap('id'),
                    ));
                }
            }, $lockSql);

            return;
        }

        if (! Schema::hasColumn('users', 'bio')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->text('bio')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'dismissed_hints')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('dismissed_hints')->nullable();
            });
        }

        if (! $hasLanguageColumn) {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('preferred_admin_language_id')->nullable();
            });
        }

        if (! $hasExpectedForeignKey) {
            Schema::table('users', function (Blueprint $table) use ($languageColumn, $languagesTable): void {
                $table->foreign($languageColumn)->references('id')->on($languagesTable)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // These columns may predate this repair and can contain user data.
    }

    private function sqliteDirectRepair(PDO $pdo, int $expectedSchemaVersion, Closure $repair, ?string $lockSql = null): void
    {
        throw_if($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION, RuntimeException::class, 'SQLite user repair requires PDO exception error mode.');

        $nested = $pdo->inTransaction();
        throw_if($nested && $lockSql === null, RuntimeException::class, 'SQLite user repair requires a write lock.');

        $pdo->exec($nested ? 'SAVEPOINT capell_user_repair' : 'BEGIN IMMEDIATE');
        try {
            if ($nested) {
                $pdo->exec($lockSql);
            }

            throw_if((int) $this->sqliteQuery($pdo, 'PRAGMA main.schema_version')->fetchColumn() !== $expectedSchemaVersion, RuntimeException::class, 'SQLite schema changed during user repair; retry after reviewing it.');

            $repair();
            $pdo->exec($nested ? 'RELEASE SAVEPOINT capell_user_repair' : 'COMMIT');
        } catch (Throwable $throwable) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT capell_user_repair');
                $pdo->exec('RELEASE SAVEPOINT capell_user_repair');
            } else {
                $pdo->exec('ROLLBACK');
            }

            throw $throwable;
        }
    }

    private function sqliteQuery(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query($sql);
        throw_unless($statement instanceof PDOStatement, RuntimeException::class, 'SQLite schema inspection failed.');

        return $statement;
    }

    /**
     * Strictly finds the outer table-definition parentheses; it never edits the input.
     *
     * @return array{int, int}
     */
    private function sqliteTableDefinitionBounds(string $sql): array
    {
        $length = strlen($sql);
        $depth = 0;
        $open = null;
        $close = null;
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            if ($character === '-' && $next === '-') {
                $end = strpos($sql, "\n", $index + 2);
                $index = $end === false ? $length : $end;

                continue;
            }

            if ($character === '/' && $next === '*') {
                $end = strpos($sql, '*/', $index + 2);
                throw_if($end === false, RuntimeException::class, 'Unterminated SQLite comment.');

                $index = $end + 1;

                continue;
            }

            if (in_array($character, ["'", '"', '`', '['], true)) {
                $endQuote = $character === '[' ? ']' : $character;
                $closed = false;
                while (++$index < $length) {
                    if ($sql[$index] !== $endQuote) {
                        continue;
                    }

                    if ($character !== '[' && ($sql[$index + 1] ?? '') === $endQuote) {
                        $index++;

                        continue;
                    }

                    $closed = true;
                    break;
                }

                throw_unless($closed, RuntimeException::class, 'Unterminated SQLite quoted token.');

                continue;
            }

            if ($character === '(') {
                if ($depth === 0) {
                    throw_if($open !== null, RuntimeException::class, 'Unexpected additional top-level expression.');

                    $open = $index;
                }

                $depth++;
            } elseif ($character === ')') {
                throw_if($depth === 0, RuntimeException::class, 'Unexpected SQLite closing parenthesis.');

                $depth--;
                if ($depth === 0) {
                    $close = $index;
                }
            }
        }

        throw_if($open === null || $close === null || $depth !== 0, RuntimeException::class, 'Incomplete SQLite table definition.');

        return [$open, $close];
    }

    /**
     * Return a CREATE TABLE for a temporary copy, preserving the original definition verbatim.
     * Deliberately refuse schema-qualified, commented or otherwise unfamiliar prefixes/suffixes.
     */
    private function sqliteCopyDefinition(string $sql, string $original, string $temporary, string $foreignTable): string
    {
        [$open, $close] = $this->sqliteTableDefinitionBounds($sql);
        $identifier = '(?:"(?:[^"]|"")*"|`(?:[^`]|``)*`|\[[^\]]*\]|[A-Za-z_]\w*)';
        $prefix = substr($sql, 0, $open);
        throw_if(preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(' . $identifier . ')\s*$/iD', $prefix, $matches) !== 1, RuntimeException::class, 'Unsupported SQLite CREATE TABLE prefix.');

        $quoted = $matches[1];
        $actual = match ($quoted[0]) {
            '"' => str_replace('""', '"', substr($quoted, 1, -1)),
            '`' => str_replace('``', '`', substr($quoted, 1, -1)),
            '[' => substr($quoted, 1, -1),
            default => $quoted,
        };
        throw_if($actual !== $original, RuntimeException::class, 'SQLite table name does not match.');

        $suffix = substr($sql, $close + 1);
        throw_if(preg_match('/^\s*(?:(?:STRICT|WITHOUT\s+ROWID)(?:\s*,\s*(?:STRICT|WITHOUT\s+ROWID))?)?\s*;?\s*$/iD', $suffix) !== 1, RuntimeException::class, 'Unsupported SQLite CREATE TABLE suffix.');

        $quote = static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"';

        return 'CREATE TABLE ' . $quote($temporary) . ' ' . substr($sql, $open, $close - $open)
            . ', FOREIGN KEY ("preferred_admin_language_id") REFERENCES ' . $quote($foreignTable)
            . ' ("id") ON DELETE SET NULL' . substr($sql, $close);
    }

    /** Rebuild supported SQLite schemas atomically, refusing unknown shapes before changes. */
    private function sqliteRebuildUsers(PDO $pdo, string $users, string $languages, ?Closure $afterPhase = null, ?int $expectedSchemaVersion = null): void
    {
        $quote = static fn (string $value): string => '"' . str_replace('"', '""', $value) . '"';
        throw_if($pdo->inTransaction(), RuntimeException::class, 'SQLite user repair requires no active transaction.');

        throw_if($pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION, RuntimeException::class, 'SQLite user repair requires PDO exception error mode.');

        throw_if((int) $this->sqliteQuery($pdo, 'PRAGMA defer_foreign_keys')->fetchColumn() !== 0
            || (int) $this->sqliteQuery($pdo, 'PRAGMA writable_schema')->fetchColumn() !== 0
            || (int) $this->sqliteQuery($pdo, 'PRAGMA legacy_alter_table')->fetchColumn() !== 0, RuntimeException::class, 'Unsupported SQLite schema or deferred-foreign-key mode.');

        $foreignKeys = (int) $this->sqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        throw_if((int) $this->sqliteQuery($pdo, 'PRAGMA foreign_keys')->fetchColumn() !== 0, RuntimeException::class, 'Unable to disable SQLite foreign keys safely.');

        $started = false;
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $started = true;
            throw_if($expectedSchemaVersion !== null && (int) $this->sqliteQuery($pdo, 'PRAGMA main.schema_version')->fetchColumn() !== $expectedSchemaVersion, RuntimeException::class, 'SQLite schema changed during user repair; retry after reviewing it.');

            $phase = static function (string $name) use ($afterPhase): void {
                if ($afterPhase instanceof Closure) {
                    $afterPhase($name);
                }
            };
            $tempSchema = $this->sqliteQuery($pdo, "SELECT type, name FROM sqlite_temp_schema WHERE type IN ('table', 'view', 'trigger')")->fetchAll(PDO::FETCH_ASSOC);
            $tempObjects = array_column($tempSchema, 'name');
            foreach ($tempSchema as $object) {
                $name = $object['name'];
                throw_if($object['type'] !== 'table' || strcasecmp((string) $name, $users) === 0 || strcasecmp((string) $name, $languages) === 0, RuntimeException::class, 'SQLite temporary schema shadows a required table.');
            }

            $schema = $pdo->prepare("SELECT type, name, tbl_name, sql FROM main.sqlite_schema WHERE type IN ('table', 'index', 'trigger', 'view') ORDER BY type, name");
            $schema->execute();
            $objects = $schema->fetchAll(PDO::FETCH_ASSOC);
            $original = null;
            $replay = [];
            $sequenceExists = false;
            foreach ($objects as $object) {
                throw_if($object['type'] === 'view' || ($object['type'] === 'trigger' && $object['tbl_name'] !== $users), RuntimeException::class, 'SQLite views and external triggers require manual schema review.');

                if ($object['type'] === 'table' && $object['name'] === 'sqlite_sequence') {
                    $sequenceExists = true;
                }

                if ($object['type'] === 'table' && $object['name'] === $users) {
                    $original = $object['sql'];
                }

                if ($object['tbl_name'] === $users && in_array($object['type'], ['index', 'trigger'], true) && is_string($object['sql'])) {
                    $replay[] = $object;
                }
            }

            throw_unless(is_string($original), RuntimeException::class, 'SQLite users table definition unavailable.');

            $temporary = '__capell_user_repair_' . bin2hex(random_bytes(8));
            foreach ($tempObjects as $name) {
                throw_if(strcasecmp((string) $name, $temporary) === 0, RuntimeException::class, 'SQLite temporary table collision.');
            }

            $tableList = $this->sqliteQuery($pdo, 'PRAGMA main.table_list')->fetchAll(PDO::FETCH_ASSOC);
            $tableInfo = null;
            foreach ($tableList as $table) {
                if ($table['name'] === $users) {
                    $tableInfo = $table;
                }

                throw_if($table['name'] === $temporary, RuntimeException::class, 'SQLite temporary table collision.');
            }

            throw_if($tableInfo === null || $tableInfo['type'] !== 'table' || ! array_key_exists('wr', $tableInfo), RuntimeException::class, 'Unsupported SQLite users table.');

            $columns = $this->sqliteQuery($pdo, 'PRAGMA main.table_xinfo(' . $quote($users) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $names = [];
            $copyColumns = [];
            $storedColumns = [];
            $identityColumns = [];
            foreach ($columns as $column) {
                if ((int) $column['pk'] > 0) {
                    $identityColumns[] = $column['name'];
                }

                if ((int) $column['hidden'] === 3) {
                    $storedColumns[] = $column['name'];
                }

                $names[] = strtolower($column['name']);
                if ((int) $column['hidden'] === 0) {
                    $copyColumns[] = $quote($column['name']);
                } elseif (! in_array((int) $column['hidden'], [2, 3], true)) {
                    throw new RuntimeException('Unsupported SQLite hidden column.');
                }
            }

            $copyDefinition = $this->sqliteCopyDefinition($original, $users, $temporary, $languages);
            throw_if($columns === [] || ! in_array('preferred_admin_language_id', $names, true), RuntimeException::class, 'SQLite users column unavailable.');

            if ((int) $tableInfo['wr'] === 0) {
                $rowId = array_find(['rowid', '_rowid_', 'oid'], fn ($candidate): bool => ! in_array($candidate, $names, true));

                throw_if($rowId === null, RuntimeException::class, 'SQLite rowid is shadowed; manual review required.');

                array_unshift($copyColumns, $quote($rowId));
                $identityColumns = [$rowId];
            } elseif ($identityColumns === []) {
                throw new RuntimeException('SQLite users row identity unavailable.');
            }

            $count = (int) $this->sqliteQuery($pdo, 'SELECT COUNT(*) FROM ' . $quote($users))->fetchColumn();
            throw_if($this->sqliteQuery($pdo, 'PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC) !== false, RuntimeException::class, 'Pre-existing SQLite foreign-key violations require repair first.');

            $sequence = null;
            if ($sequenceExists) {
                $sequenceQuery = $pdo->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
                $sequenceQuery->execute([$users]);
                $sequence = $sequenceQuery->fetchColumn();
                $sequenceQuery->closeCursor();
            }

            $phase('prepared');
            $pdo->exec($copyDefinition);
            $phase('created');
            $list = implode(', ', $copyColumns);
            $pdo->exec('INSERT INTO ' . $quote($temporary) . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $quote($users));
            $phase('copied');
            if ($storedColumns !== []) {
                $identity = array_map(static fn (string $name): string => 'original.' . $quote($name) . ' IS copied.' . $quote($name) . ' COLLATE BINARY', $identityColumns);
                $values = array_map(static fn (string $name): string => '(typeof(original.' . $quote($name) . ') = typeof(copied.' . $quote($name) . ') AND original.' . $quote($name) . ' IS copied.' . $quote($name) . ' COLLATE BINARY)', $storedColumns);
                $comparison = 'SELECT 1 FROM ' . $quote($users) . ' AS original WHERE NOT EXISTS (SELECT 1 FROM ' . $quote($temporary) . ' AS copied WHERE ' . implode(' AND ', [...$identity, ...$values]) . ') LIMIT 1';
                throw_if($this->sqliteQuery($pdo, $comparison)->fetchColumn() !== false, RuntimeException::class, 'SQLite stored generated values changed during user repair.');
            }

            $pdo->exec('DROP TABLE ' . $quote($users));
            $phase('dropped');
            $pdo->exec('ALTER TABLE ' . $quote($temporary) . ' RENAME TO ' . $quote($users));
            $phase('renamed');
            foreach (['bio', 'dismissed_hints'] as $column) {
                if (! in_array($column, $names, true)) {
                    $pdo->exec('ALTER TABLE ' . $quote($users) . ' ADD COLUMN ' . $quote($column) . ' TEXT');
                }
            }

            $phase('columns');
            foreach ($replay as $object) {
                $pdo->exec($object['sql']);
            }

            if ($sequence !== false && $sequence !== null) {
                $deleteSequence = $pdo->prepare('DELETE FROM sqlite_sequence WHERE name = ?');
                $deleteSequence->execute([$users]);
                $restoreSequence = $pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)');
                $restoreSequence->execute([$users, $sequence]);
            }

            $phase('replayed');
            $checkObject = $pdo->prepare('SELECT sql FROM main.sqlite_schema WHERE type = ? AND name = ? AND tbl_name = ?');
            foreach ($replay as $object) {
                $checkObject->execute([$object['type'], $object['name'], $users]);
                $actualSql = $checkObject->fetchColumn();
                $checkObject->closeCursor();
                throw_if($actualSql !== $object['sql'], RuntimeException::class, 'SQLite schema object changed during user repair.');
            }

            throw_if((int) $this->sqliteQuery($pdo, 'SELECT COUNT(*) FROM ' . $quote($users))->fetchColumn() !== $count
                || $this->sqliteQuery($pdo, 'PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC) !== false, RuntimeException::class, 'SQLite user repair verification failed.');

            $phase('verified');
            $pdo->exec('COMMIT');
        } catch (Throwable $throwable) {
            if ($started) {
                $pdo->exec('ROLLBACK');
            }

            throw $throwable;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ' . ($foreignKeys ? 'ON' : 'OFF'));
        }
    }
};
