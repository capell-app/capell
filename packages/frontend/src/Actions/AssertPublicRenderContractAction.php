<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\Packages\BuildPackageCapabilityGraphAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Models\Blueprint;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\FrontendRenderAudience;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Exceptions\PublicRenderContractViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

/**
 * @method static void run(Response $response)
 */
final class AssertPublicRenderContractAction
{
    use AsObject;

    /**
     * @var list<string>
     */
    private const array LIVEWIRE_MARKERS = [
        'wire:snapshot',
        'wire:effects',
        'wire:id',
        'Livewire.start',
        'livewire/update',
    ];

    /**
     * @var list<string>
     */
    private const array INTERNAL_MARKERS = [
        'vendor/capell-app/',
        '/packages/capell/',
        'frontendContextToken',
        'previewSignature',
    ];

    public function handle(Response $response): void
    {
        if ($this->audience() !== FrontendRenderAudience::Public) {
            return;
        }

        try {
            AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response);

            $content = $response->getContent();

            if (! is_string($content) || $content === '') {
                return;
            }

            if (! $this->contentIsHtml($response)) {
                return;
            }

            $this->assertNoInternalMarkers($content);
            $this->assertLivewireAllowed($content);

            $this->rememberContractPassed($content);

            if (config('capell-frontend.public_render_contract_events.record_passed', false) === true) {
                RecordPublicRenderContractEventAction::run('passed', $response);
            }
        } catch (PublicRenderContractViolationException $publicRenderContractViolationException) {
            RecordPublicRenderContractEventAction::run(
                result: 'failed',
                response: $response,
                reason: $publicRenderContractViolationException->reason,
                matchedMarker: $publicRenderContractViolationException->matched,
                category: $publicRenderContractViolationException->category,
            );

            throw $publicRenderContractViolationException;
        }
    }

    private function rememberContractPassed(string $content): void
    {
        $hash = hash('xxh128', $content);

        if (app()->bound(FrontendContextReader::class)) {
            $reader = resolve(FrontendContextReader::class);
            $reader->setFrontendData('publicHtmlSafetyInspected', true);
            $reader->setFrontendData('publicHtmlSafetyInspectedHash', $hash);
        }

        if (! app()->bound('request')) {
            return;
        }

        $request = request();

        if (! $request instanceof Request) {
            return;
        }

        $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_PASSED_ATTRIBUTE, true);
        $request->attributes->set(AssertPublicHtmlContainsNoAuthoringSurfaceAction::SAFE_INSPECTION_HASH_ATTRIBUTE, $hash);
    }

    private function audience(): FrontendRenderAudience
    {
        if (! app()->bound(FrontendContextReader::class)) {
            return FrontendRenderAudience::Public;
        }

        $audience = resolve(FrontendContextReader::class)->getFrontendData('renderAudience');

        if ($audience instanceof FrontendRenderAudience) {
            return $audience;
        }

        if (is_string($audience)) {
            return FrontendRenderAudience::tryFrom($audience) ?? FrontendRenderAudience::Public;
        }

        return FrontendRenderAudience::Public;
    }

    private function contentIsHtml(Response $response): bool
    {
        $contentType = (string) $response->headers->get('Content-Type', '');

        return $contentType === '' || str_contains(strtolower($contentType), 'text/html');
    }

    private function assertNoInternalMarkers(string $html): void
    {
        foreach (self::INTERNAL_MARKERS as $marker) {
            if (stripos($html, $marker) === false) {
                continue;
            }

            $this->fail('Public HTML contains a Capell internal marker.', $marker);
        }
    }

    private function assertLivewireAllowed(string $html): void
    {
        if (! $this->containsAny($html, self::LIVEWIRE_MARKERS)) {
            return;
        }

        $graph = BuildPackageCapabilityGraphAction::run();

        if ($graph->packagesWith(PackageCapability::RequiresLivewire) !== []) {
            return;
        }

        if ($this->currentPageAllowsLivewire()) {
            return;
        }

        $this->fail('Public HTML contains Livewire runtime state without a package capability allowing it.', 'livewire');
    }

    private function currentPageAllowsLivewire(): bool
    {
        if (! app()->bound(FrontendContextReader::class)) {
            return false;
        }

        $page = resolve(FrontendContextReader::class)->page();

        if (! $page instanceof Pageable) {
            return false;
        }

        $type = $page instanceof Model ? $this->loadedPageType($page) : null;

        if ($type?->is_livewire === true) {
            return true;
        }

        $strategy = RenderingStrategyEnum::tryFrom((string) ($page->meta['rendering_strategy'] ?? ''))
            ?? RenderingStrategyEnum::tryFrom((string) ($type?->meta['rendering_strategy'] ?? ''));

        return $strategy === RenderingStrategyEnum::FullLivewire
            || $strategy === RenderingStrategyEnum::BladeWithIslands;
    }

    private function loadedPageType(Model $page): ?Blueprint
    {
        $relations = $page->getRelations();
        $type = $relations['blueprint'] ?? null;

        return $type instanceof Blueprint ? $type : null;
    }

    /**
     * @param  list<string>  $markers
     */
    private function containsAny(string $html, array $markers): bool
    {
        return array_any($markers, fn ($marker): bool => stripos($html, (string) $marker) !== false);
    }

    private function fail(string $message, string $matched): never
    {
        Log::warning('capell-frontend: public render contract violation', [
            'matched' => $matched,
        ]);

        throw new PublicRenderContractViolationException($message, $matched);
    }
}
