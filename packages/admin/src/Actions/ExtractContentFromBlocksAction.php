<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use JsonSerializable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static string run(array<int, mixed> $blocks)
 */
class ExtractContentFromBlocksAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, mixed>  $blocks
     */
    public function handle(array $blocks): string
    {
        $lines = collect($blocks)
            ->map(fn (mixed $block): ?string => $this->extractContentLine($block))
            ->filter(fn (?string $line): bool => $line !== null && $line !== '')
            ->values();

        if ($lines->isEmpty()) {
            return '';
        }

        return $lines->implode("\n");
    }

    private function extractContentLine(mixed $block): ?string
    {
        if (is_string($block)) {
            return $block;
        }

        if (is_array($block)) {
            if (($block['type'] ?? null) !== 'content') {
                return null;
            }

            // Flat shape: ['type' => 'content', 'content' => '<p>…</p>'].
            if (array_key_exists('content', $block)) {
                return $this->normalizeBlockContent($block['content']);
            }

            // Canonical nested shape produced by the content block factory and
            // MutateContentPresenterAction: ['type' => 'content', 'data' => ['content' => '<p>…</p>']].
            return $this->normalizeBlockContent($block['data']['content'] ?? null);
        }

        if (is_object($block)) {
            // Public properties
            $typeProp = $block->type ?? null;
            $contentProp = $block->content ?? null;
            if ($typeProp === 'content') {
                return $this->normalizeBlockContent($contentProp);
            }

            // Methods accessors (best-effort)
            if (method_exists($block, 'getType') && method_exists($block, 'getContent')) {
                $type = $block->getType();
                $content = $block->getContent();
                if ($type === 'content') {
                    return $this->normalizeBlockContent($content);
                }
            }

            // Arrayable / JsonSerializable style
            if (method_exists($block, 'toArray')) {
                /** @var array<string,mixed> $array */
                $array = $block->toArray();
                if (($array['type'] ?? null) === 'content') {
                    return $this->normalizeBlockContent($array['content'] ?? null);
                }
            }

            if ($block instanceof JsonSerializable) {
                /** @var array<string,mixed>|mixed $json */
                $json = $block->jsonSerialize();
                if (is_array($json) && ($json['type'] ?? null) === 'content') {
                    return $this->normalizeBlockContent($json['content'] ?? null);
                }
            }

            return null;
        }

        return null;
    }

    private function normalizeBlockContent(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content;
        }

        if (is_array($content) && ($content['type'] ?? null) === 'doc') {
            return RichContentRenderer::make($content)->toHtml();
        }

        return null;
    }
}
