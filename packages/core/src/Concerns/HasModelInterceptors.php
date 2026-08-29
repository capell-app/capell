<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use BackedEnum;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Models\ModelInterceptorRegistry;
use Illuminate\Database\Eloquent\Model;

trait HasModelInterceptors
{
    /**
     * @param  class-string<object>  $interceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null  $key
     */
    public function registerModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        resolve(ModelInterceptorRegistry::class)->registerModelInterceptor($model, $interceptorClass, $key, $priority);
        if (! app()->bound(RecordsExtensionContributionReceipt::class)) {
            return;
        }

        resolve(RecordsExtensionContributionReceipt::class)->recordContribution(
            ExtensionContributionType::Model,
            'model-interceptor:' . $model . ':' . $interceptorClass . ':' . $this->interceptorKeyValue($key),
            $interceptorClass,
            self::class,
            'runtime',
        );
    }

    /**
     * @param  class-string<object>  $interceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null  $key
     */
    public function unregisterModelInterceptor(string $model, string $interceptorClass, null|array|string|BackedEnum $key = null): void
    {
        resolve(ModelInterceptorRegistry::class)->unregisterModelInterceptor($model, $interceptorClass, $key);
    }

    /**
     * @param  class-string<object>  $oldInterceptorClass
     * @param  class-string<object>  $newInterceptorClass
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null  $key
     */
    public function replaceModelInterceptor(string $model, string $oldInterceptorClass, string $newInterceptorClass, null|array|string|BackedEnum $key = null, int $priority = 0): void
    {
        resolve(ModelInterceptorRegistry::class)->replaceModelInterceptor($model, $oldInterceptorClass, $newInterceptorClass, $key, $priority);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @return TModel
     */
    public function createModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        return resolve(ModelInterceptorRegistry::class)->createModel($model, $key, $persist, $interceptorInterface);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum  $key
     * @return TModel
     */
    public function createOrUpdateModel(string $model, array|string|BackedEnum $key, callable $persist, string $interceptorInterface): object
    {
        return resolve(ModelInterceptorRegistry::class)->createOrUpdateModel($model, $key, $persist, $interceptorInterface);
    }

    /**
     * @param  array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null  $key
     * @return array<int, class-string<object>>
     */
    public function getInterceptorsForModelAndKey(string $model, null|array|string|BackedEnum $key): array
    {
        return resolve(ModelInterceptorRegistry::class)->getInterceptorsForModelAndKey($model, $key);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mergeModelInterceptorData(array $defaults, array $data): array
    {
        return resolve(ModelInterceptorRegistry::class)->mergeModelInterceptorData($defaults, $data);
    }

    /** @param array<string, string|int|float|bool|BackedEnum>|string|BackedEnum|null $key */
    private function interceptorKeyValue(null|array|string|BackedEnum $key): string
    {
        if ($key instanceof BackedEnum) {
            return (string) $key->value;
        }

        if (is_array($key)) {
            return md5((string) json_encode($this->canonicaliseInterceptorKey($key), JSON_THROW_ON_ERROR));
        }

        return $key ?? 'default';
    }

    /**
     * @param  array<string, string|int|float|bool|BackedEnum>  $key
     * @return array<string, string|int|float|bool>
     */
    private function canonicaliseInterceptorKey(array $key): array
    {
        $normalised = [];
        foreach ($key as $name => $value) {
            $normalised[$name] = $value instanceof BackedEnum ? $value->value : $value;
        }
        ksort($normalised);

        return $normalised;
    }
}
