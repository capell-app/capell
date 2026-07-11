<?php

declare(strict_types=1);

$mediaDisks = array_values(array_filter(array_map(
    static fn (string $disk): string => trim($disk),
    explode(',', (string) env('CAPELL_BACKUP_MEDIA_DISKS', '')),
)));

return [
    'enabled' => (bool) env('CAPELL_BACKUP_ENABLED', false),
    'disk' => env('CAPELL_BACKUP_DISK'),
    'prefix' => env('CAPELL_BACKUP_PREFIX', 'capell-backups'),
    'connection' => env('CAPELL_BACKUP_DB_CONNECTION'),
    'media_disks' => $mediaDisks,
    'max_age_hours' => (int) env('CAPELL_BACKUP_MAX_AGE_HOURS', 26),
    'minimum_retained' => (int) env('CAPELL_BACKUP_MINIMUM_RETAINED', 7),
    'retain' => (int) env('CAPELL_BACKUP_RETAIN', 30),
    'process_timeout_seconds' => (int) env('CAPELL_BACKUP_PROCESS_TIMEOUT_SECONDS', 3600),
    'scratch' => [
        'database_prefix' => env('CAPELL_BACKUP_SCRATCH_DATABASE_PREFIX', 'capell_restore_'),
        'sqlite_directory' => env('CAPELL_BACKUP_SCRATCH_SQLITE_DIRECTORY', storage_path('app/capell-restore')),
    ],
    'binaries' => [
        'mysqldump' => env('CAPELL_BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        'mysql' => env('CAPELL_BACKUP_MYSQL_BINARY', 'mysql'),
        'pg_dump' => env('CAPELL_BACKUP_PG_DUMP_BINARY', 'pg_dump'),
        'psql' => env('CAPELL_BACKUP_PSQL_BINARY', 'psql'),
    ],
];
