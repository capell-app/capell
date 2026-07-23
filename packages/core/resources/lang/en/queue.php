<?php

declare(strict_types=1);

return [
    'connection_missing' => 'Queue connection ":connection" is not configured.',
    'sync_not_supported' => 'Queue connection ":connection" uses the sync driver. Configure an asynchronous queue and start a worker before continuing.',
    'database_table_missing' => 'Queue connection ":connection" uses the database driver, but its ":table" table is missing. Run the queue table migration before continuing.',
    'database_storage_unavailable' => 'Queue connection ":connection" uses the database driver, but its ":table" table could not be checked. Verify the database connection and run the queue table migration before continuing.',
];
