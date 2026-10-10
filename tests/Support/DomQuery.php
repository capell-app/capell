<?php

declare(strict_types=1);

/**
 * Typed DOM helpers so rendered-output assertions state outcomes without
 * repeating nullable-node and false-result handling at every call site.
 */
function domXPath(string $html): DOMXPath
{
    expect($html)->not->toBe('');

    $document = new DOMDocument;
    $previous = libxml_use_internal_errors(true);

    try {
        $document->loadHTML($html);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    return new DOMXPath($document);
}

/** @return DOMNodeList<DOMNameSpaceNode|DOMNode> */
function domNodes(DOMXPath $page, string $query, ?DOMNode $context = null): DOMNodeList
{
    $nodes = $page->query($query, $context);

    throw_if($nodes === false, InvalidArgumentException::class, 'Invalid XPath query: ' . $query);

    return $nodes;
}

function domCount(DOMXPath $page, string $query, ?DOMNode $context = null): int
{
    return domNodes($page, $query, $context)->length;
}

/** @return list<DOMElement> */
function domElements(DOMXPath $page, string $query, ?DOMNode $context = null): array
{
    $elements = [];

    foreach (domNodes($page, $query, $context) as $node) {
        if ($node instanceof DOMElement) {
            $elements[] = $node;
        }
    }

    return $elements;
}

function domElement(DOMXPath $page, string $query, ?DOMNode $context = null): DOMElement
{
    $element = domNodes($page, $query, $context)->item(0);

    throw_unless($element instanceof DOMElement, InvalidArgumentException::class, 'No element matches XPath query: ' . $query);

    return $element;
}

function domText(DOMNode $node): string
{
    return $node->nodeValue ?? '';
}

function domAttribute(DOMElement $element, string $name): string
{
    return $element->hasAttribute($name) ? $element->getAttribute($name) : '';
}
