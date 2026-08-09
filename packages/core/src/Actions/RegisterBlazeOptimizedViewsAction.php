<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\BlazeOptimizationData;
use FilesystemIterator;
use Livewire\Blaze\Blaze;
use Livewire\Blaze\Config as BlazeConfig;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class RegisterBlazeOptimizedViewsAction
{
    use AsFake;
    use AsObject;

    public function handle(string $path, ?BlazeOptimizationData $optimization = null): bool
    {
        if (config('capell.blaze.enabled', true) !== true) {
            return false;
        }

        if (! class_exists(BlazeConfig::class) || ! app()->bound(BlazeConfig::class)) {
            return false;
        }

        if (! is_dir($path) && ! is_file($path)) {
            return false;
        }

        $optimization ??= new BlazeOptimizationData;

        if (config('capell.blaze.debug', false) === true) {
            config()->set('blaze.debug', true);

            if (app()->resolved('blaze')) {
                Blaze::debug();
            }
        }

        if (config('capell.blaze.throw', false) === true && app()->resolved('blaze')) {
            Blaze::throw();
        }

        resolve(BlazeConfig::class)->in(
            $path,
            compile: $optimization->compile,
            memo: $optimization->memo,
            fold: $optimization->fold,
        );

        foreach ($this->bladeViewPaths($path) as $viewPath) {
            if (! $this->requiresStandardBladeCompiler($viewPath)) {
                continue;
            }

            resolve(BlazeConfig::class)->in($viewPath, compile: false, memo: false, fold: false);
        }

        return true;
    }

    /** @return list<string> */
    private function bladeViewPaths(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! str_ends_with((string) $file->getFilename(), '.blade.php')) {
                continue;
            }

            $paths[] = $file->getPathname();
        }

        return $paths;
    }

    private function requiresStandardBladeCompiler(string $path): bool
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return false;
        }

        return preg_match(
            '/(?:\A\s*<\?php\s*(?:declare\s*\(|use\s+[A-Za-z_\\\\])|@blaze-standard-compiler)/s',
            $contents,
        ) === 1;
    }
}
