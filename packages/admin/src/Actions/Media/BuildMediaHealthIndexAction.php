<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Core\Models\Media;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMediaHealthIndexAction
{
    use AsFake;
    use AsObject;

    /** @return array<int, string> */
    public function handle(): array
    {
        /** @var Collection<int, Media> $media */
        $media = SiteAccess::current()->scopeMedia(
            SiteAccess::current()->query(Media::class)->with(['translations.language']),
        )->get();

        $states = [];

        foreach ($media as $record) {
            $states[(int) $record->getKey()] = BuildMediaHealthStateAction::run($record)->primaryIssue();
        }

        return $states;
    }
}
