<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use DOMDocument;
use DOMElement;
use DOMNode;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static array run(string $html)
 */
class HtmlToArrayAction
{
    use AsFake;
    use AsObject;

    public function handle(string $html): array
    {
        $dom = $this->loadDom($html);
        $parent = $this->getRootElement($dom);
        if (! $parent instanceof DOMElement) {
            return [];
        }

        return $this->convertChildrenToArray($parent);
    }

    private function loadDom(string $html): DOMDocument
    {
        $wrappedHtml = '<root>' . $html . '</root>';
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $dom;
    }

    private function getRootElement(DOMDocument $dom): ?DOMElement
    {
        $roots = $dom->getElementsByTagName('root');

        return $roots->length > 0 ? $roots->item(0) : null;
    }

    private function convertChildrenToArray(DOMElement $parent): array
    {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $elements[] = $this->elementToArray($child);
            } elseif ($child->nodeType === XML_TEXT_NODE && trim((string) $child->nodeValue) !== '') {
                $elements[] = [
                    'text' => $this->normalizeWhitespace($child->nodeValue),
                    'children' => [],
                ];
            }
        }

        return $elements;
    }

    private function elementToArray(DOMNode $node): array
    {
        $result = [];
        if ($node instanceof DOMElement) {
            $result['tag'] = $node->tagName;
            $result['attributes'] = $this->extractAttributes($node);
        }

        $textContent = '';
        $hasElementChildren = false;
        $children = [];
        foreach ($node->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $child->nodeValue;
                if (trim((string) $text) !== '') {
                    $textContent .= $text;
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $hasElementChildren = true;
                $children[] = $this->elementToArray($child);
            }
        }

        if ($hasElementChildren) {
            $result['text'] = null;
            $result['children'] = $children;
        } else {
            $normalized = $this->normalizeWhitespace($textContent);
            $result['text'] = $normalized !== '' ? $normalized : null;
            $result['children'] = [];
        }

        return $result;
    }

    private function extractAttributes(DOMElement $node): array
    {
        $attributes = [];
        foreach ($node->attributes ?? [] as $attr) {
            $attributes[$attr->name] = $attr->value;
        }

        return $attributes;
    }

    private function normalizeWhitespace(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', $text);
        $normalized = $normalized !== null ? trim($normalized) : null;

        return $normalized !== '' ? $normalized : null;
    }
}
