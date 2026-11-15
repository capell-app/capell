<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Security\SanitizesPublicHtml;
use Capell\Core\Enums\HtmlSanitizerProfile;
use Capell\Core\Support\CapellSiteSpecConstraints;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SanitizeSiteSpecSectionHtmlAction
{
    use AsFake;
    use AsObject;

    public function handle(string $html): string
    {
        throw_if(mb_strlen($html) > CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH, InvalidArgumentException::class, 'Site spec section HTML exceeds the maximum length.');

        return resolve(SanitizesPublicHtml::class)->sanitize($html, HtmlSanitizerProfile::RichText);
    }
}
