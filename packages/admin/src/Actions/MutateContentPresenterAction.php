<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Enums\ContentStructure;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static null|string|array<int|string, mixed> run(mixed $content, ?ContentStructure $contentStructure = null, bool $force = false)
 */
class MutateContentPresenterAction
{
    use AsFake;
    use AsObject;

    /**
     * @return null|string|array<int|string, mixed>
     */
    public function handle(mixed $content, ?ContentStructure $contentStructure = null, bool $force = false): null|string|array
    {
        $contentStructure ??= ContentStructure::Html;

        return $this->mutateContent($contentStructure, $content, $force);
    }

    /**
     * @return null|string|array<int|string, mixed>
     */
    private function mutateContent(ContentStructure $contentStructure, mixed $content, bool $force): mixed
    {
        return match ($contentStructure) {
            ContentStructure::Blocks => $this->mutateBlocksContent($content, $force),
            ContentStructure::Html => $this->mutateHtmlContent($content),
        };
    }

    /**
     * @return array<int|string, mixed>
     */
    private function mutateBlocksContent(mixed $content, bool $force): array
    {
        $content = $this->normalizeRichEditorDocument($content);

        if (is_string($content)) {
            return [
                ['type' => 'content', 'data' => ['content' => $this->normalizeEditorContentString($content)]],
            ];
        }

        if ($force && (! is_array($content) || ! array_key_exists('type', $content) || ! array_key_exists('data', $content))) {
            return [
                ['type' => 'content', 'data' => ['content' => $content]],
            ];
        }

        return $content;
    }

    private function normalizeRichEditorDocument(mixed $content): mixed
    {
        if (! is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return $content;
        }

        return RichContentRenderer::make($content)->toHtml();
    }

    private function normalizeEditorContentString(string $content): string
    {
        $decodedContent = json_decode($content, true);

        if (! is_array($decodedContent)) {
            return $content;
        }

        if (array_is_list($decodedContent)) {
            return ExtractContentFromBlocksAction::run($decodedContent);
        }

        if (! array_key_exists('content', $decodedContent)) {
            return '';
        }

        return $content;
    }

    private function mutateHtmlContent(mixed $content): ?string
    {
        if (is_array($content)) {
            return ExtractContentFromBlocksAction::run($content);
        }

        $decodedContent = json_decode((string) $content, true);

        if (is_array($decodedContent)) {
            return ExtractContentFromBlocksAction::run($decodedContent);
        }

        if (is_string($content)) {
            return $content;
        }

        return null;
    }
}
