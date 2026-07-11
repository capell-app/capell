<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class MediaModel
{
    /** @return class-string<Model> */
    public static function class(): string
    {
        $configured = config('capell.media.model');

        if (! is_string($configured) || ! is_subclass_of($configured, Model::class)) {
            return Media::class;
        }

        return $configured;
    }

    /** @return Builder<Model> */
    public static function query(): Builder
    {
        $class = self::class();

        return $class::query();
    }

    public static function instance(): Model
    {
        $class = self::class();

        return new $class;
    }
}
