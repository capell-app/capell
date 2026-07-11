<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\BlazeOptimizationData;
use Livewire\Blaze\Blaze;
use Livewire\Blaze\Config as BlazeConfig;
use Lorisleiva\Actions\Concerns\AsObject;

final class RegisterBlazeOptimizedViewsAction
{
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

        return true;
    }
}
