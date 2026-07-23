<?php

declare(strict_types=1);

use Capell\Core\Support\Backup\DatabaseBackupProcessError;
use Capell\Core\Support\Diagnostics\Checks\DatabaseBackupBinariesCheck;
use Capell\Core\Support\Diagnostics\Checks\RuntimeToolingCheck;
use Capell\Core\Support\Diagnostics\Checks\SharedCacheStoreCheck;
use Illuminate\Support\Facades\Config;

describe('SharedCacheStoreCheck', function (): void {
    it('fails for a node-local cache driver and explains the multi-node consequence', function (): void {
        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'file');
        Config::set('capell.multi_node', true);

        $result = new SharedCacheStoreCheck()->check();

        expect($result->passed)->toBeFalse()
            ->and($result->id)->toBe('core.cache.shared-store')
            ->and($result->message)->toContain('local to one node')
            ->and($result->remediation)->toContain('shared Redis or Memcached');
    });

    it('passes for a node-local cache driver on a declared single-node installation', function (): void {
        Config::set('cache.default', 'file');
        Config::set('cache.stores.file.driver', 'file');
        Config::set('capell.multi_node', false);

        $result = new SharedCacheStoreCheck()->check();

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toContain('single application node');
    });

    it('passes for a shareable cache driver', function (): void {
        Config::set('cache.default', 'redis');
        Config::set('cache.stores.redis.driver', 'redis');

        $result = new SharedCacheStoreCheck()->check();

        expect($result->passed)->toBeTrue()
            ->and($result->evidence)->toMatchArray(['store' => 'redis', 'driver' => 'redis']);
    });
});

describe('DatabaseBackupBinariesCheck', function (): void {
    it('skips the check when backups are disabled', function (): void {
        Config::set('backup.enabled', false);

        $result = new DatabaseBackupBinariesCheck()->check();

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toContain('Backups are disabled');
    });

    it('passes for sqlite because it needs no external binary', function (): void {
        Config::set('backup.enabled', true);
        Config::set('backup.database_connection', 'sqlite');
        Config::set('database.connections.sqlite.driver', 'sqlite');

        $result = new DatabaseBackupBinariesCheck()->check();

        expect($result->passed)->toBeTrue()
            ->and($result->message)->toContain('no external backup binary');
    });

    it('fails and names the config key when a mysql binary is missing', function (): void {
        Config::set('backup.enabled', true);
        Config::set('backup.database_connection', 'mysql');
        Config::set('database.connections.mysql.driver', 'mysql');
        Config::set('backup.binaries.mysqldump', '/nonexistent/capell-doctor-mysqldump');
        Config::set('backup.binaries.mysql', '/nonexistent/capell-doctor-mysql');

        $result = new DatabaseBackupBinariesCheck()->check();

        expect($result->passed)->toBeFalse()
            ->and($result->id)->toBe('core.backup.database-binaries')
            ->and($result->message)->toContain('backup.binaries.mysqldump');
    });
});

describe('RuntimeToolingCheck', function (): void {
    it('reports process execution availability as evidence', function (): void {
        $result = new RuntimeToolingCheck()->check();

        expect($result->id)->toBe('core.runtime.tooling')
            ->and($result->evidence)->toHaveKey('proc_open');
    });

    it('does not fail for missing tooling when the server does not run it', function (): void {
        // A production server that installs extensions and builds assets during
        // deployment has neither Composer nor Node. The doctor's exit code gates
        // capell:install, so reporting that as a failure would block a healthy host.
        Config::set('capell.server_side_tooling', false);

        $result = withoutToolingOnPath(fn (): mixed => new RuntimeToolingCheck()->check());

        expect($result->passed)->toBeTrue()
            ->and($result->evidence['server_side_tooling'])->toBeFalse()
            ->and($result->evidence['composer'])->toBeFalse()
            ->and($result->message)->toContain('information only');
    });

    it('fails for missing tooling when the server is declared to run it', function (): void {
        Config::set('capell.server_side_tooling', true);

        $result = withoutToolingOnPath(fn (): mixed => new RuntimeToolingCheck()->check());

        expect($result->passed)->toBeFalse()
            ->and($result->message)->toContain('not found on PATH')
            ->and($result->remediation)->toContain('CAPELL_SERVER_SIDE_TOOLING');
    });
});

/**
 * Run a callback with a PATH that contains no Composer or Node toolchain, which is
 * what a deployment-built production server looks like.
 *
 * @param  callable(): mixed  $callback
 */
function withoutToolingOnPath(callable $callback): mixed
{
    $originalPath = getenv('PATH');
    $emptyPath = sys_get_temp_dir() . '/capell-doctor-empty-path';

    if (! is_dir($emptyPath)) {
        mkdir($emptyPath, recursive: true);
    }

    putenv('PATH=' . $emptyPath);
    $_SERVER['PATH'] = $emptyPath;

    try {
        return $callback();
    } finally {
        putenv('PATH=' . (is_string($originalPath) ? $originalPath : ''));
        $_SERVER['PATH'] = is_string($originalPath) ? $originalPath : '';
    }
}

describe('DatabaseBackupProcessError', function (): void {
    it('names the binary and its config key when the executable is missing', function (): void {
        $message = DatabaseBackupProcessError::message(
            'create',
            'mysql',
            '/nonexistent/capell-doctor-mysqldump',
            'backup.binaries.mysqldump',
            new RuntimeException('generic failure'),
        );

        expect($message)->toContain('was not found')
            ->and($message)->toContain('backup.binaries.mysqldump')
            ->and($message)->toContain('connection [mysql]');
    });

    it('falls back to the throwable message when the binary exists', function (): void {
        $message = DatabaseBackupProcessError::message(
            'create',
            'mysql',
            PHP_BINARY,
            'backup.binaries.mysqldump',
            new RuntimeException('access denied for user'),
        );

        expect($message)->toContain('access denied for user')
            ->and($message)->not->toContain('was not found');
    });
});
