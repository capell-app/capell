<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Exceptions\UnsafeSvgUploadException;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Throwable;

/**
 * The SvgUpload sanitiser profile. Uploaded SVG is parsed as XML and rebuilt
 * from an element and attribute allowlist in the SVG namespace: scripts, event
 * handlers, foreign content, animation, style sheets, external references and
 * entity declarations never survive.
 */
final class SvgUploadSanitizer
{
    public const int MAX_BYTES = 2_097_152;

    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const string XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    /** @var array<string, true> */
    private const array ELEMENTS = [
        'circle' => true, 'clipPath' => true, 'defs' => true, 'desc' => true, 'ellipse' => true,
        'feBlend' => true, 'feColorMatrix' => true, 'feComposite' => true, 'feDropShadow' => true,
        'feFlood' => true, 'feGaussianBlur' => true, 'feMerge' => true, 'feMergeNode' => true,
        'feMorphology' => true, 'feOffset' => true, 'filter' => true, 'g' => true, 'line' => true,
        'linearGradient' => true, 'marker' => true, 'mask' => true, 'path' => true, 'pattern' => true,
        'polygon' => true, 'polyline' => true, 'radialGradient' => true, 'rect' => true, 'stop' => true,
        'svg' => true, 'symbol' => true, 'text' => true, 'title' => true, 'tspan' => true, 'use' => true,
    ];

    /** @var array<string, true> */
    private const array ATTRIBUTES = [
        'aria-hidden' => true, 'aria-label' => true, 'class' => true, 'clip-path' => true,
        'clip-rule' => true, 'clipPathUnits' => true, 'color' => true, 'cx' => true, 'cy' => true,
        'd' => true, 'display' => true, 'dominant-baseline' => true, 'dx' => true, 'dy' => true,
        'fill' => true, 'fill-opacity' => true, 'fill-rule' => true, 'filter' => true,
        'filterUnits' => true, 'flood-color' => true, 'flood-opacity' => true, 'focusable' => true,
        'font-family' => true, 'font-size' => true, 'font-style' => true, 'font-weight' => true,
        'fx' => true, 'fy' => true, 'gradientTransform' => true, 'gradientUnits' => true,
        'height' => true, 'id' => true, 'in' => true, 'in2' => true, 'k1' => true, 'k2' => true,
        'k3' => true, 'k4' => true, 'letter-spacing' => true, 'marker-end' => true,
        'marker-mid' => true, 'marker-start' => true, 'markerHeight' => true, 'markerUnits' => true,
        'markerWidth' => true, 'mask' => true, 'maskUnits' => true, 'mode' => true, 'offset' => true,
        'opacity' => true, 'operator' => true, 'orient' => true, 'patternContentUnits' => true,
        'patternTransform' => true, 'patternUnits' => true, 'points' => true,
        'preserveAspectRatio' => true, 'r' => true, 'radius' => true, 'refX' => true, 'refY' => true,
        'result' => true, 'role' => true, 'rx' => true, 'ry' => true, 'shape-rendering' => true,
        'spreadMethod' => true, 'stdDeviation' => true, 'stop-color' => true, 'stop-opacity' => true,
        'stroke' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true,
        'stroke-opacity' => true, 'stroke-width' => true, 'style' => true, 'text-anchor' => true,
        'transform' => true, 'type' => true, 'values' => true, 'vector-effect' => true,
        'version' => true, 'viewBox' => true, 'visibility' => true, 'width' => true, 'x' => true,
        'x1' => true, 'x2' => true, 'y' => true, 'y1' => true, 'y2' => true,
    ];

    /**
     * Presentation properties an inline style may set. Anything else, and any
     * value that could load a resource or escape the declaration, drops the style.
     *
     * @var array<string, true>
     */
    private const array STYLE_PROPERTIES = [
        'clip-rule' => true, 'color' => true, 'display' => true, 'fill' => true, 'fill-opacity' => true,
        'fill-rule' => true, 'font-family' => true, 'font-size' => true, 'font-style' => true,
        'font-weight' => true, 'opacity' => true, 'stop-color' => true, 'stop-opacity' => true,
        'stroke' => true, 'stroke-dasharray' => true, 'stroke-dashoffset' => true,
        'stroke-linecap' => true, 'stroke-linejoin' => true, 'stroke-miterlimit' => true,
        'stroke-opacity' => true, 'stroke-width' => true, 'text-anchor' => true, 'visibility' => true,
    ];

    /**
     * @throws UnsafeSvgUploadException
     */
    public function sanitize(string $contents): string
    {
        throw_if(trim($contents) === '', UnsafeSvgUploadException::class, 'The SVG upload is empty.');
        throw_if(strlen($contents) > self::MAX_BYTES, UnsafeSvgUploadException::class, 'The SVG upload is too large to check safely.');
        // Checked over the whole document: entity expansion and external entities start in a DOCTYPE wherever it sits.
        throw_if(stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false, UnsafeSvgUploadException::class, 'SVG uploads may not contain a DOCTYPE or ENTITY declaration.');

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET);
        } catch (Throwable $throwable) {
            throw new UnsafeSvgUploadException('The SVG upload could not be parsed safely.', 0, $throwable);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        throw_unless(
            $loaded && $root instanceof DOMElement && $root->localName === 'svg' && $root->namespaceURI === self::SVG_NAMESPACE,
            UnsafeSvgUploadException::class,
            'The SVG upload must be an SVG document.',
        );

        $this->sanitizeAttributes($root);
        $this->sanitizeChildren($root);

        $sanitized = $document->saveXML($root);

        return is_string($sanitized) ? $sanitized : '';
    }

    public function sanitizeOrEmpty(string $contents): string
    {
        try {
            return $this->sanitize($contents);
        } catch (UnsafeSvgUploadException) {
            return '';
        }
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                // Text only: comments, processing instructions and CDATA are dropped.
                if ($child::class !== DOMText::class) {
                    $parent->removeChild($child);
                }

                continue;
            }

            if ($child->namespaceURI !== self::SVG_NAMESPACE || ! isset(self::ELEMENTS[$child->localName])) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child);
            $this->sanitizeChildren($child);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($element->attributes, false);

        foreach ($attributes as $attribute) {
            if (! $this->isSafeAttribute($element, $attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private function isSafeAttribute(DOMElement $element, DOMAttr $attribute): bool
    {
        $name = $attribute->localName ?? '';
        $value = $attribute->value;

        if ($name === 'href' && ($attribute->namespaceURI === null || $attribute->namespaceURI === self::XLINK_NAMESPACE)) {
            return $element->localName === 'use' && preg_match('/^#[A-Za-z][A-Za-z0-9_.:-]{0,80}$/', $value) === 1;
        }

        if ($attribute->namespaceURI !== null || ! isset(self::ATTRIBUTES[$name])) {
            return false;
        }

        if ($name === 'style') {
            return $this->isSafeStyle($value);
        }

        return $this->isSafeValue($value);
    }

    /**
     * A value may reference a resource only as a same-document fragment: url(#id).
     */
    private function isSafeValue(string $value): bool
    {
        $normalized = strtolower((string) preg_replace('/[\x00-\x20]+/', '', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if (str_contains($normalized, '\\') || str_contains($normalized, 'javascript:') || str_contains($normalized, 'expression(')) {
            return false;
        }

        $withoutFragments = (string) preg_replace('/url\(([\'"]?)#[a-z][a-z0-9_.:-]{0,80}\1\)/', '', $normalized);

        return ! str_contains($withoutFragments, 'url(') && ! str_contains($withoutFragments, '@import');
    }

    private function isSafeStyle(string $style): bool
    {
        foreach (explode(';', $style) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }

            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            $property = strtolower(trim((string) $property));

            if ($value === null || ! isset(self::STYLE_PROPERTIES[$property]) || ! $this->isSafeValue($value)) {
                return false;
            }

            if (preg_match('/^[\sA-Za-z0-9#%.,()\'"-]*$/', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
