<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use DOMAttr;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Decides whether an SVG is a static drawing.
 *
 * An SVG opened directly from the storage URL is a document on the site origin,
 * so script, event handlers, embedded HTML and script URLs in it run with the
 * visitor's session. Anything this cannot parse or prove inert is refused.
 */
final class SvgContentInspector
{
    private const string XHTML_NAMESPACE = 'http://www.w3.org/1999/xhtml';

    private const int MAXIMUM_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    private const array ACTIVE_ELEMENTS = ['script', 'foreignobject', 'iframe', 'object', 'embed', 'handler', 'listener'];

    /** @var list<string> */
    private const array ANIMATION_ELEMENTS = ['animate', 'set', 'animatetransform', 'animatemotion'];

    /** @var list<string> */
    private const array EMBEDDING_ELEMENTS = ['image', 'feimage', 'use', 'tref', 'pattern', 'filter', 'lineargradient', 'radialgradient', 'textpath', 'mpath'];

    /** @var list<string> */
    private const array URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'data', 'base', 'to', 'from', 'by', 'values'];

    public function isStatic(string $contents): bool
    {
        // Parsing cost grows with size; a drawing larger than this is not inspected.
        if (trim($contents) === '' || strlen($contents) > self::MAXIMUM_BYTES) {
            return false;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // No entity substitution and no network: a declared entity can hide
            // a script URL from this inspection and expand without bound.
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            return false;
        }

        if (strtolower($document->documentElement->localName ?? '') !== 'svg') {
            return false;
        }

        if ($document->doctype instanceof DOMDocumentType && ($document->doctype->entities->length > 0 || trim((string) $document->doctype->internalSubset) !== '')) {
            return false;
        }

        return $this->nodeIsStatic($document);
    }

    private function nodeIsStatic(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            // A stylesheet instruction can transform the drawing into a script-bearing document.
            if ($child instanceof DOMProcessingInstruction || $child->nodeType === XML_ENTITY_REF_NODE) {
                return false;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            if (! $this->elementIsStatic($child) || ! $this->nodeIsStatic($child)) {
                return false;
            }
        }

        return true;
    }

    private function elementIsStatic(DOMElement $element): bool
    {
        $name = strtolower($element->localName ?? '');

        if ($element->namespaceURI === self::XHTML_NAMESPACE || in_array($name, self::ACTIVE_ELEMENTS, true)) {
            return false;
        }

        if ($name === 'style' && $this->styleIsActive($element->textContent)) {
            return false;
        }

        foreach ($element->attributes as $attribute) {
            if (! $attribute instanceof DOMAttr) {
                continue;
            }

            $attributeName = strtolower($attribute->localName ?? '');

            if (str_starts_with($attributeName, 'on')) {
                return false;
            }

            if ($attributeName === 'style' && $this->styleIsActive($attribute->value)) {
                return false;
            }

            if (in_array($attributeName, self::URL_ATTRIBUTES, true) && $this->urlIsActive($attribute->value)) {
                return false;
            }

            // An image converter on the server resolves these references when it
            // renders the drawing, so they may only point inside the file itself.
            if ($attributeName === 'href' && in_array($name, self::EMBEDDING_ELEMENTS, true) && ! $this->referenceIsInternal($attribute->value)) {
                return false;
            }

            // Animation can assign a script URL or a handler after load.
            if ($attributeName === 'attributename' && in_array($name, self::ANIMATION_ELEMENTS, true)) {
                $target = strtolower(trim($attribute->value));
                $target = str_contains($target, ':') ? substr($target, (int) strrpos($target, ':') + 1) : $target;

                if ($target === 'href' || str_starts_with($target, 'on')) {
                    return false;
                }
            }
        }

        return true;
    }

    private function urlIsActive(string $value): bool
    {
        // Browsers ignore whitespace and control characters inside a scheme.
        $normalised = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $value));

        foreach (explode(';', $normalised) as $candidate) {
            if (str_starts_with($candidate, 'javascript:') || str_starts_with($candidate, 'vbscript:')) {
                return true;
            }

            if (str_starts_with($candidate, 'data:') && preg_match('#^data:image/(png|jpe?g|gif|webp|avif)[;,]#', $candidate . ';') !== 1) {
                return true;
            }
        }

        return false;
    }

    private function referenceIsInternal(string $value): bool
    {
        $reference = trim($value);

        return $reference === ''
            || str_starts_with($reference, '#')
            || preg_match('#^data:image/(png|jpe?g|gif|webp|avif)[;,]#i', $reference) === 1;
    }

    private function styleIsActive(string $style): bool
    {
        $normalised = strtolower((string) preg_replace('/[\x00-\x20\x7f]+|\\\\/', '', $style));

        return str_contains($normalised, 'javascript:')
            || str_contains($normalised, 'vbscript:')
            || str_contains($normalised, 'expression(')
            || str_contains($normalised, '@import')
            || str_contains($normalised, '-moz-binding');
    }
}
