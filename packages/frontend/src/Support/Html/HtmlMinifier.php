<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Html;

use Capell\Frontend\Contracts\HtmlMinifier as HtmlMinifierContract;
use voku\helper\HtmlMin;

final class HtmlMinifier implements HtmlMinifierContract
{
    private const string PROTECTED_ATTRIBUTE_TOKEN_PREFIX = 'CAPELL_HTML_MINIFIER_ATTRIBUTE_';

    private ?HtmlMin $htmlMin = null;

    public function minify(string $html): string
    {
        if ($html === '') {
            return '';
        }

        [$html, $protectedAttributes] = $this->protectAlpineAttributes($html);

        return strtr($this->htmlMin()->minify($html), $protectedAttributes);
    }

    private function htmlMin(): HtmlMin
    {
        if ($this->htmlMin instanceof HtmlMin) {
            return $this->htmlMin;
        }

        $htmlMin = new HtmlMin;

        $htmlMin->doOptimizeAttributes(false);
        $htmlMin->doSortHtmlAttributes(false);
        $htmlMin->doSortCssClassNames(false);
        $htmlMin->doRemoveOmittedHtmlTags(false);
        $htmlMin->doRemoveOmittedQuotes(false);
        $htmlMin->doRemoveHttpPrefixFromAttributes(false);
        $htmlMin->doRemoveHttpsPrefixFromAttributes(false);

        return $this->htmlMin = $htmlMin;
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function protectAlpineAttributes(string $html): array
    {
        $protectedAttributes = [];

        $html = (string) preg_replace_callback(
            '/(?<lead>\s)(?<name>x-[A-Za-z0-9:._-]+|[:@][A-Za-z0-9:._-]+)=(?<quote>["\'])(?<value>.*?)\k<quote>/s',
            static function (array $matches) use (&$protectedAttributes): string {
                $token = self::PROTECTED_ATTRIBUTE_TOKEN_PREFIX . count($protectedAttributes);
                $protectedAttributes[$token] = $matches['value'];

                // Rebuild from the captured parts rather than str_replace()ing
                // the raw value into the match: a value that also occurs inside
                // the attribute name (e.g. value `x` in `x-data="x"`) would
                // otherwise tokenize the name too, and HtmlMin's attribute-name
                // lowercasing then leaves that token unrestorable.
                return $matches['lead'] . $matches['name'] . '=' . $matches['quote'] . $token . $matches['quote'];
            },
            $html,
        );

        return [$html, $protectedAttributes];
    }
}
