<?php

declare(strict_types=1);

namespace Capell\Core\Support\Content;

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Model;

final class ContentStructureResolver
{
    public static function resolve(Model $model): ContentStructure
    {
        $translatable = self::resolveTranslatable($model);

        if ($translatable instanceof Page) {
            if (! $translatable->relationLoaded('blueprint')) {
                $translatable->load('blueprint');
            }

            $structure = $translatable->content_structure;
        } elseif ($translatable instanceof Blueprintable) {
            if (! $translatable->relationLoaded('blueprint')) {
                $translatable->load('blueprint');
            }

            $blueprint = $translatable->getRelation('blueprint');
            $structure = $blueprint instanceof Blueprint ? $blueprint->content_structure : null;
        } else {
            $structure = null;
        }

        return $structure instanceof ContentStructure ? $structure : ContentStructure::Html;
    }

    private static function resolveTranslatable(Model $model): ?Model
    {
        if ($model->getAttribute('translatable_id') === null) {
            return null;
        }

        if ($model->relationLoaded('translatable')) {
            $translatable = $model->getRelation('translatable');

            return $translatable instanceof Model ? $translatable : null;
        }

        if (! method_exists($model, 'translatable')) {
            return null;
        }

        $translatable = $model->translatable()->first();
        $model->setRelation('translatable', $translatable);

        return $translatable instanceof Model ? $translatable : null;
    }
}
