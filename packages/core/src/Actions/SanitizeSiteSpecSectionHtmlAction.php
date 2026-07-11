<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class SanitizeSiteSpecSectionHtmlAction
{
    use AsObject;

    public const MAX_INPUT_LENGTH = 20000;

    private static ?HtmlSanitizer $sanitizer = null;

    public function handle(string $html): string
    {
        if (mb_strlen($html) > self::MAX_INPUT_LENGTH) {
            throw new InvalidArgumentException('Site spec section HTML exceeds the maximum length.');
        }

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
                ->withMaxInputLength(self::MAX_INPUT_LENGTH),
        );
    }
}
