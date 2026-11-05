<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;

/** Collect legacy head contributions without changing unrelated markup. */
final class HeadMetadataCollector
{
    private const META_KEYS = ['robots', 'og:title', 'og:description', 'og:type', 'og:url', 'og:site_name', 'twitter:card', 'twitter:title', 'twitter:description'];

    /** @var array<string, string|array<string, mixed>> */
    private array $values = [];

    public function collect(string $html): string
    {
        $previousValues = $this->values;
        $result = preg_replace_callback('~<!--.*?-->|(?<template><template\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>(?:(?&template)|(?!</?template\b)[^<]++|<(?!/?template\b))*+</template\s*>)|<(?<raw>style|textarea|title|xmp|iframe|noembed|noframes|noscript)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>.*?</\k<raw>\s*>|<meta\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>|<script\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>.*?</script\s*>|</?[a-z][a-z0-9:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*+>~is', function (array $match): string {
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
                $managed = false;
                foreach (['name', 'property'] as $attribute) {
                    $key = strtolower($meta->getAttribute($attribute));
                    if (in_array($key, self::META_KEYS, true)) {
                        $value = $meta->getAttribute('content');
                        $this->values[$key] = $key === 'robots' && isset($this->values[$key]) && is_string($this->values[$key])
                            ? HeadMetadataRegistry::mergeRobots($this->values[$key], $value)
                            : $value;
                        $managed = true;
                    }
                }

                if ($managed) {
                    return '';
                }
            }

            $script = $document->getElementsByTagName('script')->item(0);
            if (! $script instanceof DOMElement || strtolower($script->getAttribute('type')) !== 'application/ld+json') {
                return $match[0];
            }

            // The agent bridge reads this separate schema directly from the page.
            if ($script->hasAttribute('data-capell-agent-schema')) {
                return $match[0];
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

        if ($result === null) {
            $this->values = $previousValues;
            Log::warning('Unable to collect head metadata: ' . preg_last_error_msg());

            return $html;
        }

        return $result;
    }

    /** @return array<string, string|array<string, mixed>> */
    public function values(): array
    {
        return $this->values;
    }
}
