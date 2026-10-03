<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SlugEditorClientForTest
{
    /**
     * Execute the handlers on the rendered native input, rather than inventing
     * a manual flag that the browser did not submit.
     *
     * @return array{slug: string, manual: bool, editing: bool, modified: bool}
     */
    public static function update(string $html, string $statePath, string $slug, string $interaction, bool $manual = false): array
    {
        throw_if($html === '', RuntimeException::class, 'Rendered slug editor is empty.');

        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($document);
        $inputs = $xpath->query('//input[@x-ref="slugInput"]');
        throw_unless($inputs !== false, RuntimeException::class, 'Slug input query failed.');

        foreach ($inputs as $input) {
            if (! $input instanceof DOMElement) {
                continue;
            }

            if ($input->getAttribute('wire:model') !== $statePath) {
                continue;
            }

            $wrapper = $input->parentNode;
            while ($wrapper instanceof DOMElement && ! $wrapper->hasAttribute('x-data')) {
                $wrapper = $wrapper->parentNode;
            }

            throw_unless($wrapper instanceof DOMElement, RuntimeException::class, 'Slug editor Alpine scope is missing.');

            $process = new Process(['node', __DIR__ . '/slug-editor-client.cjs']);
            $process->setInput(json_encode([
                'alpine' => $wrapper->getAttribute('x-data'),
                'input' => $input->getAttribute('x-on:input'),
                'enter' => $input->getAttribute('x-on:keydown.enter.prevent') ?: $input->getAttribute('x-on:keydown.enter'),
                'slug' => $slug,
                'interaction' => $interaction,
                'manual' => $manual,
            ], JSON_THROW_ON_ERROR));
            $process->mustRun();

            /** @var array{slug: string, manual: bool, editing: bool, modified: bool} $result */
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        throw new RuntimeException('Rendered slug input is missing: ' . $statePath);
    }
}
