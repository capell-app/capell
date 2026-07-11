<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Html;

use Capell\Frontend\Contracts\HtmlMinifier as HtmlMinifierContract;
use voku\helper\HtmlMin;

final class HtmlMinifier implements HtmlMinifierContract
{
    private const string PROTECTED_ATTRIBUTE_TOKEN_PREFIX = 'CAPELL_HTML_MINIFIER_ATTRIBUTE_';

    public function minify(string $html): string
    {
        if ($html === '') {
            return '';
        }

        [$html, $protectedAttributes] = $this->protectAlpineAttributes($html);

        $htmlMin = new HtmlMin;

        $htmlMin->doOptimizeAttributes(false);
        $htmlMin->doSortHtmlAttributes(false);
        $htmlMin->doSortCssClassNames(false);
        $htmlMin->doRemoveOmittedHtmlTags(false);
        $htmlMin->doRemoveOmittedQuotes(false);
        $htmlMin->doRemoveHttpPrefixFromAttributes(false);
        $htmlMin->doRemoveHttpsPrefixFromAttributes(false);

        return strtr($htmlMin->minify($html), $protectedAttributes);
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function protectAlpineAttributes(string $html): array
    {
        $protectedAttributes = [];

        $html = (string) preg_replace_callback(
            '/(?<attribute>\s(?:x-[A-Za-z0-9:._-]+|[:@][A-Za-z0-9:._-]+)=(?<quote>["\'])(?<value>.*?)(\k<quote>))/s',
            static function (array $matches) use (&$protectedAttributes): string {
                $token = self::PROTECTED_ATTRIBUTE_TOKEN_PREFIX . count($protectedAttributes);
                $protectedAttributes[$token] = $matches['value'];

                return str_replace($matches['value'], $token, $matches['attribute']);
            },
            $html,
        );

        return [$html, $protectedAttributes];
    }
}
