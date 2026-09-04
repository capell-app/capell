<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Actions\ContentGraph\BuildContentImpactPreviewAction;
use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Capell\Core\Models\Media;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMediaImpactPreviewAction
{
    use AsFake;
    use AsObject;

    public function handle(Media $media): ?ContentImpactPreviewData
    {
        $actor = auth()->user();

        if (! $actor instanceof Authenticatable || ! Gate::forUser($actor)->allows('update', $media)) {
            return null;
        }

        return BuildContentImpactPreviewAction::run(
            $media,
            fn (Model $dependency): bool => Gate::forUser($actor)->allows('view', $dependency),
        );
    }
}
