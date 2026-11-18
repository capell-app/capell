<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Support\Locale\HtmlLanguageAttribute;
use Capell\Frontend\Data\PublicDocumentLanguageData;
use Capell\Frontend\Facades\Frontend;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildPublicDocumentLanguageDataAction
{
    use AsFake;
    use AsObject;

    public function handle(mixed $language = null): PublicDocumentLanguageData
    {
        $served = ResolveDocumentLanguageAction::run(Frontend::page(), $language instanceof Language ? $language : Frontend::language());

        return new PublicDocumentLanguageData(
            HtmlLanguageAttribute::forLanguage($served),
            $served?->direction() ?? Language::directionForCode(app()->getLocale()),
        );
    }
}
