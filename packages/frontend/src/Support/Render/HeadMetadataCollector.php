<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use DOMDocument;
use DOMElement;

/** Collect legacy head contributions without changing unrelated markup. */
final class HeadMetadataCollector
{
    private const META_KEYS = ['robots', 'og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'twitter:card', 'twitter:title', 'twitter:description'];

    /** @var array<string, string|array<string, mixed>> */
    private array $values = [];

    public function collect(string $html): string
    {
        return (string) preg_replace_callback('~<!--.*?-->|<(?:style|textarea|title|xmp|iframe|noembed|noframes)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>.*?</(?:style|textarea|title|xmp|iframe|noembed|noframes)\s*>|<meta\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>|<script\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>.*?</script\s*>|</?[a-z][a-z0-9:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is', function (array $match): string {
            if (preg_match('/^<(?:meta|script)\b/i', $match[0]) !== 1) {
                return $match[0];
            }

            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8"><html><head>' . $match[0] . '</head></html>');
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $meta = $document->getElementsByTagName('meta')->item(0);
            if ($meta instanceof DOMElement) {
                $key = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
                if (in_array($key, self::META_KEYS, true)) {
                    $this->values[$key] = $meta->getAttribute('content');

                    return '';
                }
            }

            $script = $document->getElementsByTagName('script')->item(0);
            if (! $script instanceof DOMElement || strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                return $match[0];
            }

            // A script carrying a Capell data marker (the agent schema read by the agent bridge) is
            // not a head default: leave it, and its attributes, exactly as emitted.
            foreach ($script->attributes ?? [] as $attribute) {
                if (str_starts_with(strtolower($attribute->nodeName), 'data-capell-')) {
                    return $match[0];
                }
            }

            $data = json_decode($script->textContent, true);
            if (! is_array($data)) {
                return $match[0];
            }

            $nodes = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            $remaining = [];
            foreach ($nodes as $node) {
                $type = is_array($node) ? ($node['@type'] ?? null) : null;
                $types = is_array($type) ? $type : [$type];
                $managed = false;
                foreach (['WebSite', 'WebPage'] as $managedType) {
                    if (in_array($managedType, $types, true)) {
                        $this->values['jsonld:' . $managedType] = $node;
                        $managed = true;
                    }
                }

                if (! $managed) {
                    $remaining[] = $node;
                }
            }

            if (count($remaining) === count($nodes)) {
                return $match[0];
            }

            if ($remaining === []) {
                return '';
            }

            $data['@graph'] = $remaining;

            return '<script type="application/ld+json">' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) . '</script>';
        }, $html);
    }

    /** @return array<string, string|array<string, mixed>> */
    public function values(): array
    {
        return $this->values;
    }
}
