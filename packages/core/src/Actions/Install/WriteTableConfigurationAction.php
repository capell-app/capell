<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Support\Patching\PhpFileEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use RuntimeException;
use Throwable;

final class WriteTableConfigurationAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, string> $values */
    public function handle(array $values, ?string $directory = null): void
    {
        if ($values === []) {
            return;
        }

        $clearConfigCache = $directory === null && app()->configurationIsCached();
        $directory ??= config_path();
        $sources = [
            'capell' => dirname(__DIR__, 3) . '/config/capell.php',
            'permission' => base_path('vendor/spatie/laravel-permission/config/permission.php'),
            'settings' => base_path('vendor/spatie/laravel-settings/config/settings.php'),
            'tags' => base_path('vendor/spatie/laravel-tags/config/tags.php'),
            'activitylog' => base_path('vendor/spatie/laravel-activitylog/config/activitylog.php'),
        ];
        $grouped = [];
        foreach ($values as $key => $value) {
            $parts = explode('.', $key);
            $file = array_shift($parts);
            throw_unless(is_string($file) && isset($sources[$file]) && $parts !== [], RuntimeException::class, 'Unsupported table configuration key: ' . $key);
            throw_unless($value === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $value) === 1, RuntimeException::class, 'Invalid table name for ' . $key);
            $grouped[$file][] = [$parts, $value];
        }

        $outputs = [];
        foreach ($grouped as $file => $changes) {
            $path = $directory . '/' . $file . '.php';
            $source = is_file($path) ? $path : $sources[$file];
            if ($file === 'tags' && ! is_file($source)) {
                $source = dirname(__DIR__, 3) . '/stubs/tags-config.php.stub';
            }

            $editor = new PhpFileEditor($source);
            $return = (new NodeFinder)->findFirstInstanceOf($editor->getAst(), Return_::class);
            throw_unless($return instanceof Return_ && $return->expr instanceof Array_, RuntimeException::class, 'Cannot safely update configuration: ' . $path);
            foreach ($changes as [$parts, $value]) {
                $this->setArrayValue($return->expr, $parts, $value);
            }

            $outputs[$path] = $editor->print();
        }

        $staged = [];
        $backups = [];
        $replaced = [];
        try {
            foreach ($outputs as $path => $content) {
                $temporary = $directory . '/capell-table-' . bin2hex(random_bytes(8));
                $staged[$path] = $temporary;
                throw_unless(File::put($temporary, $content) === strlen($content), RuntimeException::class, 'Cannot stage configuration: ' . $path);
                if (is_file($path)) {
                    $backup = $directory . '/capell-backup-' . bin2hex(random_bytes(8));
                    $backups[$path] = $backup;
                    throw_unless(copy($path, $backup), RuntimeException::class, 'Cannot back up configuration: ' . $path);
                    $permissions = fileperms($path);
                    if (is_int($permissions)) {
                        chmod($temporary, $permissions & 0777);
                        chmod($backup, $permissions & 0777);
                    }
                } else {
                    chmod($temporary, 0666 & ~umask());
                }
            }

            foreach ($staged as $path => $temporary) {
                throw_unless(File::move($temporary, $path), RuntimeException::class, 'Cannot publish configuration: ' . $path);
                $replaced[] = $path;
            }
        } catch (Throwable $throwable) {
            $restoreFailures = [];
            foreach (array_reverse($replaced) as $path) {
                if (isset($backups[$path])) {
                    try {
                        $restored = File::move($backups[$path], $path);
                    } catch (Throwable) {
                        $restored = false;
                    }

                    if (! $restored) {
                        $restoreFailures[] = $backups[$path];
                        unset($backups[$path]);
                    }
                } else {
                    try {
                        $removed = File::delete($path);
                    } catch (Throwable) {
                        $removed = false;
                    }

                    if (! $removed) {
                        $restoreFailures[] = $path;
                    }
                }
            }

            if ($restoreFailures !== []) {
                throw new RuntimeException('Cannot restore configuration; preserved backup or path: ' . implode(', ', $restoreFailures), 0, $throwable);
            }

            throw $throwable;
        } finally {
            foreach ([...array_values($staged), ...array_values($backups)] as $temporary) {
                if (is_file($temporary)) {
                    File::delete($temporary);
                }
            }
        }

        if ($clearConfigCache) {
            $exit = Artisan::call('config:clear');
            throw_if($exit !== 0, RuntimeException::class, 'Could not clear the cached configuration after choosing table names.');
        }

        foreach ($values as $key => $value) {
            config()->set($key, $value);
        }
    }

    /** @param list<string> $parts */
    private function setArrayValue(Array_ $array, array $parts, string $value): void
    {
        $key = array_shift($parts);
        throw_unless(is_string($key) && $key !== '', RuntimeException::class, 'Invalid configuration path.');
        $match = null;
        foreach ($array->items as $item) {
            if ($item !== null && $item->key instanceof String_ && $item->key->value === $key) {
                $match = $item;
            }
        }

        if ($match === null) {
            $match = new ArrayItem($parts === [] ? new String_($value) : new Array_([]), new String_($key));
            $array->items[] = $match;
        }

        if ($parts === []) {
            $match->value = new String_($value);

            return;
        }

        throw_unless($match->value instanceof Array_, RuntimeException::class, 'Cannot safely update dynamic configuration key: ' . $key);
        $this->setArrayValue($match->value, $parts, $value);
    }
}
