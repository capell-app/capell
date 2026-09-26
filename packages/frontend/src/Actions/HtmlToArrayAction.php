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
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrappedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

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
            $converted = $this->nodeToArray($child);

            if (is_array($converted)) {
                $elements[] = $converted;
            }
        }

        return $elements;
    }

    private function nodeToArray(DOMNode $node): ?array
    {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            return $this->elementToArray($node);
        }

        if ($node->nodeType !== XML_TEXT_NODE) {
            return null;
        }

        $text = $this->normalizeWhitespace($node->nodeValue);

        return $text !== null ? ['text' => $text, 'children' => []] : null;
    }

    private function elementToArray(DOMNode $node): array
    {
        $result = [];
        if ($node instanceof DOMElement) {
            $result['tag'] = $node->tagName;
            $result['attributes'] = $this->extractAttributes($node);
        }

        $hasElementChildren = false;
        foreach ($node->childNodes ?? [] as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $hasElementChildren = true;
            }
        }

        if ($hasElementChildren) {
            $result['text'] = null;
            $result['children'] = $node instanceof DOMElement ? $this->convertChildrenToArray($node) : [];
        } else {
            $normalized = $this->normalizeWhitespace($node->textContent);
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
