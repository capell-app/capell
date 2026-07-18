<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Capell\Core\Support\Models\ModelInterceptorRegistry;

trait HasModelInterceptors
{
    public function registerModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        resolve(ModelInterceptorRegistry::class)->registerModelInterceptor($model, $interceptorClass, $key, $priority);
    }

    public function unregisterModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null): void
    {
        resolve(ModelInterceptorRegistry::class)->unregisterModelInterceptor($model, $interceptorClass, $key);
    }

    public function replaceModelInterceptor(string $model, string $oldInterceptorClass, string $newInterceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        resolve(ModelInterceptorRegistry::class)->replaceModelInterceptor($model, $oldInterceptorClass, $newInterceptorClass, $key, $priority);
    }

    public function createModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        return resolve(ModelInterceptorRegistry::class)->createModel($model, $key, $persist, $interceptorInterface);
    }

    public function createOrUpdateModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        return resolve(ModelInterceptorRegistry::class)->createOrUpdateModel($model, $key, $persist, $interceptorInterface);
    }

    public function getInterceptorsForModelAndKey(string $model, null|array|string|BackedEnum $key): array
    {
        return resolve(ModelInterceptorRegistry::class)->getInterceptorsForModelAndKey($model, $key);
    }

    public function mergeModelInterceptorData(array $defaults, array $data): array
    {
        return resolve(ModelInterceptorRegistry::class)->mergeModelInterceptorData($defaults, $data);
    }
}
