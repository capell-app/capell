<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Fixtures;

use Capell\Core\Contracts\ModelInterceptors\BlueprintInterceptorInterface;
use Capell\Core\Models\Blueprint;

/**
 * Stands in for a package interceptor attached to a custom blueprint subject.
 */
class CustomSubjectBlueprintInterceptor implements BlueprintInterceptorInterface
{
    /** @var list<string> */
    public static array $beforeCreateCalls = [];

    /** @var list<string> */
    public static array $afterCreatedCalls = [];

    public static function reset(): void
    {
        self::$beforeCreateCalls = [];
        self::$afterCreatedCalls = [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function beforeCreate(array $data): array
    {
        self::$beforeCreateCalls[] = 'beforeCreate';

        $data['admin'] = ['notes' => 'Added by the package interceptor.'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function afterCreated(Blueprint $blueprint, array $data): void
    {
        self::$afterCreatedCalls[] = (string) $blueprint->getKey();
    }
}
