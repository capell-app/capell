<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Render;

use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Data\RenderHookEntryData;
use Capell\Frontend\Data\RenderHookFragmentCacheData;
use Capell\Frontend\Data\RenderHookFragmentReferenceData;
use Capell\Frontend\Enums\RenderHookLocation;
use LogicException;
use Throwable;

final class RenderHookFragmentRegistry
{
    private const string TOKEN_PREFIX = 'CAPELL_FRAGMENT_';

    private bool $capturing = false;

    private bool $failed = false;

    /** @var list<RenderHookFragmentReferenceData> */
    private array $references = [];

    public function beginCapture(): void
    {
        $this->capturing = true;
        $this->failed = false;
        $this->references = [];
    }

    public function endCapture(): void
    {
        $this->capturing = false;
    }

    public function isCapturing(): bool
    {
        return $this->capturing;
    }

    public function hasFailures(): bool
    {
        return $this->failed;
    }

    public function placeholder(RenderHookEntryData $entry, RenderHookContext $context): string
    {
        if (! $this->capturing) {
            throw new LogicException('Render hook fragment capture is not active.');
        }

        if ($context->item !== null) {
            throw new LogicException(sprintf('Render hook fragment [%s] requires an explicit serialisable item context.', $entry->key ?? 'unknown'));
        }

        if ($entry->stableKey() === '') {
            throw new LogicException('Render hook fragments require an owner and stable key.');
        }

        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(16));
        $this->references[] = new RenderHookFragmentReferenceData(
            token: $token,
            stableKey: $entry->stableKey(),
            location: $entry->location,
            scenario: $entry->scenario,
            target: $entry->target,
        );

        return $token;
    }

    /** @return list<RenderHookFragmentReferenceData> */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * Render all captured regions into the live response. A failed region uses
     * the supplied fallback and does not prevent other regions being rendered.
     *
     * @param  callable(RenderHookFragmentReferenceData, Throwable): string  $fallback
     */
    public function renderLive(
        string $capturedHtml,
        RenderHookRegistry $registry,
        callable $fallback,
    ): string {
        return $this->replaceTokens(
            $capturedHtml,
            fn (RenderHookFragmentReferenceData $reference): string => $this->renderReference($reference, $registry, $fallback, rehydrating: false),
        );
    }

    /**
     * Re-render a validated marker-free shell for the current request.
     *
     * @param  callable(RenderHookFragmentReferenceData, Throwable): string  $fallback
     */
    public function renderCached(
        RenderHookFragmentCacheData $cache,
        RenderHookRegistry $registry,
        callable $fallback,
    ): string {
        $html = $cache->shell;

        foreach (array_reverse($cache->fragments) as $fragment) {
            $reference = new RenderHookFragmentReferenceData(
                token: '',
                stableKey: $fragment['stableKey'],
                location: RenderHookLocation::from($fragment['location']),
                scenario: $fragment['scenario'],
                target: $fragment['target'],
            );
            $replacement = $this->renderReference($reference, $registry, $fallback, rehydrating: true);
            $offset = $fragment['offset'];

            if ($offset < 0 || $offset > strlen($html)) {
                throw new LogicException(sprintf('Render hook fragment [%s] has an invalid insertion offset.', $reference->stableKey));
            }

            $html = substr_replace($html, $replacement, $offset, 0);
        }

        return $html;
    }

    /**
     * Prepare the shell after applying the configured minifier. Tokens are
     * validated and removed before this value can be persisted.
     *
     * @param  callable(string): string  $minifier
     */
    public function prepareCache(string $capturedHtml, callable $minifier): RenderHookFragmentCacheData
    {
        if ($this->references === []) {
            return new RenderHookFragmentCacheData($capturedHtml, []);
        }

        $minified = $minifier($capturedHtml);
        $positions = [];

        foreach ($this->references as $reference) {
            $count = substr_count($minified, $reference->token);
            if ($count !== 1) {
                throw new LogicException(sprintf('Render hook fragment token [%s] was not preserved exactly once by the HTML minifier.', $reference->stableKey));
            }

            $position = strpos($minified, $reference->token);
            if ($position === false) {
                throw new LogicException(sprintf('Render hook fragment token [%s] was not found after HTML minification.', $reference->stableKey));
            }

            $positions[] = [$reference, $position];
        }

        usort($positions, static fn (array $left, array $right): int => $left[1] <=> $right[1]);

        $shell = str_replace(array_map(static fn (array $position): string => $position[0]->token, $positions), '', $minified);
        $removedLength = 0;
        $fragments = [];

        foreach ($positions as [$reference, $position]) {
            $fragments[] = [
                'stableKey' => $reference->stableKey,
                'location' => $reference->location->value,
                'scenario' => $reference->scenario,
                'target' => $reference->target,
                'offset' => $position - $removedLength,
            ];
            $removedLength += strlen($reference->token);
        }

        return new RenderHookFragmentCacheData($shell, $fragments);
    }

    /**
     * @param  callable(RenderHookFragmentReferenceData): string  $renderer
     */
    private function replaceTokens(string $html, callable $renderer): string
    {
        foreach ($this->references as $reference) {
            $replacement = $renderer($reference);
            if (substr_count($html, $reference->token) !== 1) {
                throw new LogicException(sprintf('Render hook fragment token [%s] was not present exactly once.', $reference->stableKey));
            }

            $html = str_replace($reference->token, $replacement, $html);
        }

        return $html;
    }

    /**
     * @param  callable(RenderHookFragmentReferenceData, Throwable): string  $fallback
     */
    private function renderReference(
        RenderHookFragmentReferenceData $reference,
        RenderHookRegistry $registry,
        callable $fallback,
        bool $rehydrating,
    ): string {
        try {
            return $registry->renderContribution($reference, rehydrating: $rehydrating);
        } catch (Throwable $throwable) {
            $this->failed = true;
            report($throwable);

            return $fallback($reference, $throwable);
        }
    }
}
