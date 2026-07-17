<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Support\CapellSiteSpecConstraints;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class SanitizeSiteSpecSectionHtmlAction
{
    use AsFake;
    use AsObject;

    private static ?HtmlSanitizer $sanitizer = null;

    public function handle(string $html): string
    {
        throw_if(mb_strlen($html) > CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH, InvalidArgumentException::class, 'Site spec section HTML exceeds the maximum length.');

        return $this->sanitizer()->sanitize($html);
    }

    private function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->allowAttribute('class', '*')
                ->allowAttribute('id', '*')
                ->withMaxInputLength(CapellSiteSpecConstraints::MAX_SECTION_CONTENT_LENGTH),
        );
    }
}
