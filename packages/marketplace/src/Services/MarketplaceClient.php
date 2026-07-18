<?php

declare(strict_types=1);

namespace Capell\Marketplace\Services;

use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Support\Json\JsonCodec;
use Capell\Core\Support\Marketplace\MarketplacePayloadSigner;
use Capell\Marketplace\Actions\BuildMarketplaceConnectionContextAction;
use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Data\ExtensionFeedbackData;
use Capell\Marketplace\Data\ExtensionListingData;
use Capell\Marketplace\Data\HeartbeatResultData;
use Capell\Marketplace\Data\MarketplaceCataloguePageData;
use Capell\Marketplace\Data\MarketplaceCatalogueQueryData;
use Capell\Marketplace\Data\MarketplaceInstallAuthorizationData;
use Capell\Marketplace\Data\MarketplaceUpgradeAuthorizationData;
use Capell\Marketplace\Exceptions\PurchaseRequiredException;
use Capell\Marketplace\Models\MarketplaceInstance;
use Capell\Marketplace\Support\MarketplaceApprovalUrl;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class MarketplaceClient
{
    public const INSTANCE_NOT_REGISTERED_MESSAGE = 'Marketplace instance is not registered. Connect a Capell account before requesting authorization.';

    public const DEFAULT_EXTENSION_SORT = 'latest';

    public function __construct(
        private readonly MarketplacePayloadSigner $signer,
        private readonly MarketplaceInstanceResolver $instances,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createAccountConnectionSession(array $payload): array
    {
        return $this->postJsonData(
            '/marketplace/connections',
            $payload,
            'Marketplace could not start account connection.',
            'Marketplace did not return account connection data.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function exchangeAccountConnectionCode(array $payload): array
    {
        return $this->postJsonData(
            '/marketplace/connections/exchange',
            $payload,
            'Marketplace could not complete account connection.',
            'Marketplace did not return connected account data.',
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInstallFlow(array $payload): array
    {
        $path = '/marketplace/install-flows';
        $data = $this->postJsonData(
            $path,
            $this->installFlowPayload($payload, $path),
            'Marketplace could not start the install flow.',
            'Marketplace did not return install flow data.',
        );

        $approvalUrl = $data['approval_url'] ?? null;

        throw_unless(is_string($approvalUrl) && $approvalUrl !== '', RuntimeException::class, 'Marketplace did not return an install flow approval URL.');
        $data['approval_url'] = $this->validatedApprovalUrl($approvalUrl);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function exchangeInstallFlow(array $payload): array
    {
        $path = '/marketplace/install-flows/exchange';

        return $this->postJsonData(
            $path,
            $this->installFlowPayload($payload, $path),
            'Marketplace could not complete the install flow.',
            'Marketplace did not return install flow exchange data.',
        );
    }

    /** @param array<string, mixed> $payload */
    public function heartbeat(array $payload): HeartbeatResultData
    {
        $heartbeatUrl = $this->marketplaceUrl('/instances/heartbeat');

        $response = Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->acceptJson()
            ->post($heartbeatUrl, $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'The marketplace rejected the heartbeat with HTTP status ' . $response->status() . '.'
                . $this->responseFailureDetails($response, $heartbeatUrl)
                . ' Check the marketplace URL, instance ID, and server logs.',
            );
        }

        $responseData = $response->json('data');

        if (! is_array($responseData)) {
            throw new RuntimeException(
                'The marketplace response did not include the expected data payload.'
                . $this->responseFailureDetails($response, $heartbeatUrl),
            );
        }

        $this->validateHeartbeatResponseData($responseData, $response, $heartbeatUrl);
        $responseData = $this->verifiedHeartbeatResponseData($responseData);

        return HeartbeatResultData::fromApiResponse($responseData);
    }

    public function listExtensionPage(
        MarketplaceCatalogueQueryData $query,
        bool $allowStale = false,
        bool $forceRefresh = false,
    ): MarketplaceCataloguePageData {
        $marketplaceContext = $this->marketplaceContext();
        $cachePayload = $query->toCachePayload($marketplaceContext);
        $cacheKey = 'capell-marketplace.marketplace.extensions-page.' . hash('xxh3', JsonCodec::encode($cachePayload));
        $staleCacheKey = $cacheKey . '.stale';

        if (! $forceRefresh && Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);

            if (is_array($cachedResponse)) {
                return $this->cataloguePageFromResponse($cachedResponse, $query);
            }
        }

        if (! $forceRefresh && $allowStale && Cache::has($staleCacheKey)) {
            $cachedResponse = Cache::get($staleCacheKey);

            if (is_array($cachedResponse)) {
                return $this->cataloguePageFromResponse($cachedResponse, $query, stale: true);
            }
        }

        $responseData = $this->fetchCataloguePage($query, $marketplaceContext);
        $ttl = config('capell-marketplace.marketplace.cache_ttl_seconds', 300);
        $staleTtl = config('capell-marketplace.marketplace.stale_cache_ttl_seconds', 3600);

        Cache::put($cacheKey, $responseData, $ttl);
        Cache::put($staleCacheKey, $responseData, $staleTtl);

        return $this->cataloguePageFromResponse($responseData, $query);
    }

    /**
     * @return array<string, mixed>
     */
    public function catalogueCachePayload(MarketplaceCatalogueQueryData $query): array
    {
        return $query->toCachePayload($this->marketplaceContext());
    }

    /** @return array<int, ExtensionListingData> */
    public function listExtensions(
        string $search = '',
        string $kind = '',
        bool $freeOnly = false,
        string $sort = self::DEFAULT_EXTENSION_SORT,
        ?int $priceMinCents = null,
        ?int $priceMaxCents = null,
        ?string $capellVersion = null,
        ?string $laravelVersion = null,
        ?string $livewireVersion = null,
        ?string $filamentVersion = null,
        ?string $category = null,
        array $capabilities = [],
        ?string $author = null,
        ?int $maxPages = null,
    ): array {
        $marketplaceContext = $this->marketplaceContext();
        $cachePayload = [
            'search' => $search,
            'kind' => $kind,
            'free' => $freeOnly,
            'sort' => $sort,
            'price_min_cents' => $priceMinCents,
            'price_max_cents' => $priceMaxCents,
            'capell_version' => $capellVersion,
            'laravel_version' => $laravelVersion,
            'livewire_version' => $livewireVersion,
            'filament_version' => $filamentVersion,
            'category' => $category,
            'capabilities' => $capabilities,
            'author' => $author,
            'context' => $marketplaceContext,
            'max_pages' => $maxPages,
        ];
        $cacheKey = 'capell-marketplace.marketplace.extensions.' . hash('xxh3', JsonCodec::encode($cachePayload));
        $ttl = config('capell-marketplace.marketplace.cache_ttl_seconds', 300);

        $items = Cache::remember($cacheKey, $ttl, function () use ($search, $kind, $freeOnly, $sort, $priceMinCents, $priceMaxCents, $capellVersion, $laravelVersion, $livewireVersion, $filamentVersion, $category, $capabilities, $author, $marketplaceContext, $maxPages): array {
            $params = array_filter(
                [
                    'search' => $search,
                    'kind' => $kind,
                    'free' => $freeOnly ? '1' : '',
                    'sort' => $sort,
                    'min_price_cents' => $priceMinCents === null ? '' : (string) $priceMinCents,
                    'max_price_cents' => $priceMaxCents === null ? '' : (string) $priceMaxCents,
                    'capell_version' => $capellVersion ?? '',
                    'laravel_version' => $laravelVersion ?? '',
                    'livewire_version' => $livewireVersion ?? '',
                    'filament_version' => $filamentVersion ?? '',
                    'category' => $category ?? '',
                    'capabilities' => implode(',', $capabilities),
                    'author' => $author ?? '',
                    'instance_id' => $marketplaceContext['instance_id'] ?? '',
                    'account_id' => $marketplaceContext['account_id'] ?? '',
                ],
                fn (string $value): bool => $value !== '',
            );
            $url = $this->marketplaceUrl('/extensions');
            $items = [];
            $visitedUrls = [];

            do {
                $visitedUrls[] = $url;
                ['response' => $response, 'data' => $responseItems] = $this->getCatalogueData(
                    $url,
                    $params,
                    'The marketplace catalogue is unavailable right now.',
                    'The marketplace catalogue did not return JSON extension data.',
                );

                $items = [
                    ...$items,
                    ...$responseItems,
                ];

                if (is_int($maxPages) && count($visitedUrls) >= $maxPages) {
                    break;
                }

                $url = $response->json('links.next');
                $params = [];
            } while (is_string($url) && $url !== '' && ! in_array($url, $visitedUrls, true));

            return $items;
        });

        return array_map(
            ExtensionListingData::fromApiResponse(...),
            $items,
        );
    }

    /**
     * @param  array<int, string>  $composerNames
     * @return array<string, ExtensionListingData>
     */
    public function extensionsByComposerNames(
        array $composerNames,
        string $kind = '',
        ?string $capellVersion = null,
        ?string $laravelVersion = null,
        ?string $livewireVersion = null,
        ?string $filamentVersion = null,
        bool $allowCache = true,
    ): array {
        $composerNames = array_values(array_unique(array_filter(
            array_map(
                static fn (string $composerName): ?string => ExtensionListingData::localPackageComposerName(trim($composerName)),
                $composerNames,
            ),
            static fn (?string $composerName): bool => is_string($composerName) && $composerName !== '',
        )));

        sort($composerNames);

        if ($composerNames === []) {
            return [];
        }

        $marketplaceContext = $this->marketplaceContext();
        $cachePayload = [
            'composer_names' => $composerNames,
            'kind' => $kind,
            'capell_version' => $capellVersion,
            'laravel_version' => $laravelVersion,
            'livewire_version' => $livewireVersion,
            'filament_version' => $filamentVersion,
            'context' => $marketplaceContext,
        ];
        $cacheKey = 'capell-marketplace.marketplace.extensions-by-composer.' . hash('xxh3', JsonCodec::encode($cachePayload));
        $ttl = config('capell-marketplace.marketplace.cache_ttl_seconds', 300);

        $fetchExtensions = function () use ($composerNames, $kind, $capellVersion, $laravelVersion, $livewireVersion, $filamentVersion, $marketplaceContext): array {
            $params = array_filter(
                [
                    'composer_names' => implode(',', $composerNames),
                    'kind' => $kind,
                    'capell_version' => $capellVersion ?? '',
                    'laravel_version' => $laravelVersion ?? '',
                    'livewire_version' => $livewireVersion ?? '',
                    'filament_version' => $filamentVersion ?? '',
                    'instance_id' => $marketplaceContext['instance_id'] ?? '',
                    'account_id' => $marketplaceContext['account_id'] ?? '',
                ],
                static fn (string $value): bool => $value !== '',
            );

            ['data' => $responseItems] = $this->getCatalogueData(
                $this->marketplaceUrl('/extensions/by-composer'),
                $params,
                'The marketplace exact extension lookup is unavailable right now.',
                'The marketplace exact extension lookup did not return JSON extension data.',
                marketplaceContext: $marketplaceContext,
                notFoundAsEmpty: true,
            );

            return array_is_list($responseItems) ? $responseItems : array_values($responseItems);
        };

        $items = $allowCache ? Cache::remember($cacheKey, $ttl, $fetchExtensions) : $fetchExtensions();

        $requestedComposerNames = array_flip($composerNames);
        $extensions = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $extension = ExtensionListingData::fromApiResponse($item);

            if (! array_key_exists($extension->composerName, $requestedComposerNames)) {
                continue;
            }

            $extensions[$extension->composerName] = $extension;
        }

        return $extensions;
    }

    public function getExtension(string $slug, bool $allowCache = true): ?ExtensionListingData
    {
        $path = $this->extensionPath($slug);
        $marketplaceContext = $this->marketplaceContext();
        $cacheKey = 'capell-marketplace.marketplace.extension.' . hash('xxh3', JsonCodec::encode([
            'slug' => $slug,
            'context' => $marketplaceContext,
        ]));
        $ttl = config('capell-marketplace.marketplace.cache_ttl_seconds', 300);

        $fetchExtension = fn (): ?array => $this->fetchExtensionData($path, $marketplaceContext);

        $item = $allowCache ? Cache::remember($cacheKey, $ttl, $fetchExtension) : $fetchExtension();

        return $item !== null ? ExtensionListingData::fromApiResponse($item) : null;
    }

    public function getExtensionDetail(string $slug): ?ExtensionDetailData
    {
        $path = $this->extensionPath($slug);
        $marketplaceContext = $this->marketplaceContext();
        $cacheKey = 'capell-marketplace.marketplace.extension-detail.' . hash('xxh3', JsonCodec::encode([
            'slug' => $slug,
            'context' => $marketplaceContext,
        ]));
        $ttl = config('capell-marketplace.marketplace.cache_ttl_seconds', 300);

        $data = Cache::remember(
            $cacheKey,
            $ttl,
            fn (): ?array => $this->fetchExtensionData(
                $path,
                $marketplaceContext,
                'The marketplace extension detail response did not include a data object.',
            ),
        );

        return is_array($data) ? ExtensionDetailData::fromApiResponse($data) : null;
    }

    public function createInstallAuthorization(
        string $slug,
        ?string $licenseKey,
        ?string $email,
        array $installOptions = [],
    ): MarketplaceInstallAuthorizationData {
        $response = $this->postSignedJson($this->extensionPath($slug, '/install-authorization'), [
            'license_key' => $licenseKey,
            'email' => $email,
            'app_url' => config('app.url'),
            'install_options' => $installOptions,
        ]);

        $this->throwIfPurchaseRequired($response);

        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, 'Marketplace could not authorize this install.'));
        }

        return MarketplaceInstallAuthorizationData::fromApiResponse($response->json() ?? []);
    }

    public function extensionLicenceDecision(string $slug, string $action, ?string $domain = null): ExtensionLicenceDecisionData
    {
        unset($domain);

        $data = $this->postSignedJsonData(
            $this->extensionPath($slug, '/licence-decision'),
            [
                'action' => $action,
                'app_url' => config('app.url'),
            ],
            'Marketplace could not resolve this licence decision.',
            'The marketplace licence decision response did not include a data object.',
        );

        return ExtensionLicenceDecisionData::fromApiResponse($data);
    }

    /** @return array<string, mixed> */
    public function submitExtensionFeedback(ExtensionFeedbackData $feedback, ?string $domain = null): array
    {
        unset($domain);

        return $this->postSignedJsonData(
            $this->extensionPath($feedback->slug, '/feedback'),
            [
                'rating' => $feedback->rating,
                'comment' => $feedback->comment,
                'tip' => $feedback->tip,
                'app_url' => config('app.url'),
            ],
            'Marketplace could not submit this feedback.',
            'The marketplace feedback response did not include a data object.',
        );
    }

    public function createUpgradeAuthorization(
        string $composerName,
        string $currentVersion,
        ?string $domain = null,
    ): MarketplaceUpgradeAuthorizationData {
        unset($domain);

        $response = $this->postSignedJson('/extensions/upgrade-authorization', [
            'composer_name' => $composerName,
            'current_version' => $currentVersion,
            'app_url' => config('app.url'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, 'Marketplace could not authorize this update.'));
        }

        return MarketplaceUpgradeAuthorizationData::fromApiResponse($response->json() ?? []);
    }

    /** @param array<string, mixed> $payload */
    public function recordInstallIntent(array $payload): void
    {
        try {
            $response = $this->postSignedJson('/extensions/install-intents', $payload);

            if (! $response->successful()) {
                Log::warning('capell-marketplace: install intent telemetry was rejected', [
                    'status' => $response->status(),
                    'composer_name' => $payload['composer_name'] ?? null,
                ]);
            }
        } catch (ConnectionException|RuntimeException $throwable) {
            Log::warning('capell-marketplace: install intent telemetry failed', [
                'error' => $throwable->getMessage(),
                'composer_name' => $payload['composer_name'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    public function sendFreeInstallTelemetry(array $payload): void
    {
        $payload = [
            ...$payload,
            'authorization_required' => false,
            'source' => 'marketplace_free_install',
        ];
        $payload = $this->signedFreeTelemetryPayload($payload);

        $response = Http::timeout(config('capell-marketplace.marketplace.telemetry_timeout_seconds', 3))
            ->acceptJson()
            ->post($this->marketplaceUrl('/extensions/install-intents'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, 'Marketplace could not record free install telemetry.'));
        }
    }

    /** @param array<string, mixed> $payload */
    private function signedFreeTelemetryPayload(array $payload): array
    {
        try {
            $marketplaceInstance = MarketplaceInstance::query()
                ->latest('last_heartbeat_at')
                ->first();
        } catch (Throwable) {
            return $payload;
        }

        $signingSecret = $marketplaceInstance?->signing_secret_encrypted;
        $instanceId = $marketplaceInstance?->instance_id;

        if (! is_string($signingSecret) || $signingSecret === '' || ! is_string($instanceId) || $instanceId === '') {
            return $payload;
        }

        return $this->signedMarketplacePayload($payload, $marketplaceInstance, $instanceId, $signingSecret, '/extensions/install-intents');
    }

    /**
     * @param  array{instance_id?: string, account_id?: string}  $marketplaceContext
     * @return array<string, mixed>
     */
    private function fetchCataloguePage(MarketplaceCatalogueQueryData $query, array $marketplaceContext): array
    {
        ['response' => $response, 'data' => $responseItems] = $this->getCatalogueData(
            $this->marketplaceUrl('/extensions'),
            $query->toRequestParameters($marketplaceContext),
            'The marketplace catalogue is unavailable right now.',
            'The marketplace catalogue did not return JSON extension data.',
        );

        return [
            'data' => $responseItems,
            'links' => is_array($response->json('links')) ? $response->json('links') : [],
            'meta' => is_array($response->json('meta')) ? $response->json('meta') : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function cataloguePageFromResponse(
        array $responseData,
        MarketplaceCatalogueQueryData $query,
        bool $stale = false,
    ): MarketplaceCataloguePageData {
        $items = $responseData['data'] ?? [];

        throw_unless(is_array($items), RuntimeException::class, 'The cached marketplace catalogue did not include extension data.');

        $meta = is_array($responseData['meta'] ?? null) ? $responseData['meta'] : [];
        $links = is_array($responseData['links'] ?? null) ? $responseData['links'] : [];
        $nextPageUrl = $links['next'] ?? null;

        $currentPage = $this->integerMetaValue($meta, 'current_page') ?? $query->page;
        $perPage = $this->integerMetaValue($meta, 'per_page') ?? $query->perPage;

        return new MarketplaceCataloguePageData(
            extensions: array_map(
                ExtensionListingData::fromApiResponse(...),
                array_values(array_filter($items, is_array(...))),
            ),
            total: $this->integerMetaValue($meta, 'total') ?? $this->fallbackCatalogueTotal(
                items: $items,
                currentPage: $currentPage,
                perPage: $perPage,
                nextPageUrl: is_string($nextPageUrl) && $nextPageUrl !== '' ? $nextPageUrl : null,
            ),
            currentPage: $currentPage,
            perPage: $perPage,
            nextPageUrl: is_string($nextPageUrl) && $nextPageUrl !== '' ? $nextPageUrl : null,
            stale: $stale,
        );
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private function fallbackCatalogueTotal(array $items, int $currentPage, int $perPage, ?string $nextPageUrl): int
    {
        $visibleItemCount = count($items);

        if ($nextPageUrl === null) {
            return (($currentPage - 1) * $perPage) + $visibleItemCount;
        }

        return ($currentPage * $perPage) + 1;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function integerMetaValue(array $meta, string $key): ?int
    {
        $value = $meta[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function marketplaceUrl(string $path): string
    {
        return config('capell-marketplace.marketplace.base_url') . $path;
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $path, array $payload): Response
    {
        return Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->acceptJson()
            ->post($this->marketplaceUrl($path), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postJsonData(string $path, array $payload, string $failureMessage, string $missingDataMessage): array
    {
        return $this->responseDataOrFail($this->postJson($path, $payload), $failureMessage, $missingDataMessage);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postSignedJsonData(string $path, array $payload, string $failureMessage, string $missingDataMessage): array
    {
        return $this->responseDataOrFail($this->postSignedJson($path, $payload), $failureMessage, $missingDataMessage);
    }

    /** @return array<string, mixed> */
    private function responseDataOrFail(
        Response $response,
        string $failureMessage,
        string $missingDataMessage,
    ): array {
        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, $failureMessage));
        }

        return $this->dataOrFail($response, $missingDataMessage);
    }

    /** @return array<string, mixed> */
    private function dataOrFail(Response $response, string $missingDataMessage): array
    {
        $data = $response->json('data');

        throw_unless(is_array($data), RuntimeException::class, $missingDataMessage);

        return $data;
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  array{instance_id?: string, account_id?: string}|null  $marketplaceContext
     * @return array{response: Response, data: array<int|string, mixed>}
     */
    private function getCatalogueData(
        string $url,
        array $parameters,
        string $failureMessage,
        string $missingDataMessage,
        ?array $marketplaceContext = null,
        bool $notFoundAsEmpty = false,
    ): array {
        $request = Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))->acceptJson();

        if ($marketplaceContext !== null) {
            $request->withHeaders($this->marketplaceContextHeaders($marketplaceContext));
        }

        $response = $parameters === [] ? $request->get($url) : $request->get($url, $parameters);

        if ($notFoundAsEmpty && $response->notFound()) {
            return ['response' => $response, 'data' => []];
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, $failureMessage));
        }

        $data = $response->json('data');

        throw_unless(is_array($data), RuntimeException::class, $this->friendlyResponseMessage($response, $missingDataMessage));

        return ['response' => $response, 'data' => $data];
    }

    /**
     * @param  array{instance_id?: string, account_id?: string}  $marketplaceContext
     * @return array<string, mixed>|null
     */
    private function fetchExtensionData(string $path, array $marketplaceContext, ?string $missingDataMessage = null): ?array
    {
        $response = Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->acceptJson()
            ->withHeaders($this->marketplaceContextHeaders($marketplaceContext))
            ->get($this->marketplaceUrl($path));

        if ($response->notFound()) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->friendlyResponseMessage($response, 'The marketplace extension detail is unavailable right now.'));
        }

        $data = $response->json('data');

        if ($missingDataMessage !== null) {
            throw_unless(is_array($data), RuntimeException::class, $missingDataMessage);
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postSignedJson(string $path, array $payload): Response
    {
        $marketplaceInstance = $this->instances->latest();

        $signingSecret = $marketplaceInstance?->signing_secret_encrypted;
        $instanceId = $marketplaceInstance?->instance_id;

        if (! is_string($instanceId) || $instanceId === '' || ! is_string($signingSecret) || $signingSecret === '') {
            $instanceId = config('capell-marketplace.instance.id');
            $signingSecret = config('capell-marketplace.marketplace.webhook_secret');
        }

        throw_if(! is_string($instanceId) || $instanceId === '' || ! is_string($signingSecret) || $signingSecret === '', RuntimeException::class, self::INSTANCE_NOT_REGISTERED_MESSAGE);

        $payload = $this->signedMarketplacePayload($payload, $marketplaceInstance, $instanceId, $signingSecret, $path);
        $jsonPayload = JsonCodec::encode($payload);
        $signature = $payload['signature'];

        throw_if(! is_string($signature) || $signature === '', RuntimeException::class, 'Unable to sign marketplace request payload.');

        return Http::timeout(config('capell-marketplace.marketplace.timeout_seconds', 10))
            ->withHeaders([
                'X-Capell-Instance' => $instanceId,
                'X-Capell-Signature' => $signature,
            ])
            ->withBody($jsonPayload, 'application/json')
            ->post($this->marketplaceUrl($path));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function installFlowPayload(array $payload, string $path): array
    {
        $marketplaceInstance = $this->instances->latest();
        $marketplaceContext = BuildMarketplaceConnectionContextAction::run($marketplaceInstance);
        $instanceId = $marketplaceContext['instance_id'] ?? null;
        $signingSecret = $marketplaceInstance?->signing_secret_encrypted;

        if (! is_string($instanceId) || $instanceId === '' || ! is_string($signingSecret) || $signingSecret === '') {
            return $payload;
        }

        return $this->signedMarketplacePayload($payload, $marketplaceInstance, $instanceId, $signingSecret, $path, $marketplaceContext);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $marketplaceContext
     * @return array<string, mixed>
     */
    private function signedMarketplacePayload(
        array $payload,
        ?MarketplaceInstance $marketplaceInstance,
        string $instanceId,
        string $signingSecret,
        string $path,
        ?array $marketplaceContext = null,
    ): array {
        $marketplaceContext ??= BuildMarketplaceConnectionContextAction::run($marketplaceInstance, $instanceId);

        return $this->signer->signedPayload([
            ...$payload,
            ...$marketplaceContext,
            'signature_context' => [
                'method' => 'POST',
                'path' => $path,
                'instance_id' => $instanceId,
            ],
        ], $signingSecret);
    }

    private function validatedApprovalUrl(string $approvalUrl): string
    {
        return MarketplaceApprovalUrl::validate($approvalUrl);
    }

    private function extensionPath(string $slug, string $suffix = ''): string
    {
        throw_unless(
            preg_match('/\A[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\z/i', $slug) === 1,
            InvalidArgumentException::class,
            'Marketplace extension slugs may only contain letters, numbers, dots, underscores, and hyphens.',
        );

        return '/extensions/' . rawurlencode($slug) . $suffix;
    }

    /**
     * @param  array{instance_id?: string, account_id?: string}  $marketplaceContext
     * @return array<string, string>
     */
    private function marketplaceContextHeaders(array $marketplaceContext): array
    {
        return array_filter(
            [
                'X-Capell-Instance' => $marketplaceContext['instance_id'] ?? '',
                'X-Capell-Account' => $marketplaceContext['account_id'] ?? '',
            ],
            fn (string $value): bool => $value !== '',
        );
    }

    private function throwIfPurchaseRequired(Response $response): void
    {
        if (! in_array($response->status(), [402, 403, 422], true)) {
            return;
        }

        $purchaseUrl = $response->json('data.purchase_url')
            ?? $response->json('data.checkout_url')
            ?? $response->json('purchase_url')
            ?? $response->json('checkout_url');

        if (! is_string($purchaseUrl) || $purchaseUrl === '') {
            return;
        }

        $message = $response->json('message');

        throw new PurchaseRequiredException(
            purchaseUrl: $purchaseUrl,
            message: is_string($message) && $message !== '' ? $message : 'Purchase is required before this plugin can be installed.',
        );
    }

    /** @return array{instance_id?: string, account_id?: string} */
    private function marketplaceContext(): array
    {
        $marketplaceInstance = $this->instances->latest();

        if (! $marketplaceInstance instanceof MarketplaceInstance || ! is_string($marketplaceInstance->instance_id) || $marketplaceInstance->instance_id === '') {
            return [];
        }

        return BuildMarketplaceConnectionContextAction::run($marketplaceInstance);
    }

    private function friendlyResponseMessage(Response $response, string $fallback): string
    {
        $validationError = $this->firstResponseValidationError($response);

        if ($validationError !== null) {
            return $validationError;
        }

        $message = $response->json('message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        $licenceErrors = $response->json('errors.licence');

        if (is_array($licenceErrors)) {
            $firstError = collect($licenceErrors)->first(fn (mixed $value): bool => is_string($value) && $value !== '');

            if (is_string($firstError)) {
                return $firstError;
            }
        }

        return $fallback . ' Please check the Marketplace connection and try again.';
    }

    private function firstResponseValidationError(Response $response): ?string
    {
        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return null;
        }

        foreach ($errors as $fieldErrors) {
            $fieldErrors = is_array($fieldErrors) ? $fieldErrors : [$fieldErrors];

            foreach ($fieldErrors as $error) {
                if (is_string($error) && $error !== '') {
                    return $error;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function validateHeartbeatResponseData(array $responseData, Response $response, string $heartbeatUrl): void
    {
        $instanceId = $responseData['instance_id'] ?? null;
        $signingSecret = $responseData['signing_secret'] ?? null;
        $updates = $responseData['updates'] ?? null;
        $advisories = $responseData['advisories'] ?? null;

        if (! is_string($instanceId) || $instanceId === '') {
            throw new RuntimeException(
                'The marketplace response did not include an instance ID.'
                . $this->responseFailureDetails($response, $heartbeatUrl),
            );
        }

        if ($signingSecret !== null && (! is_string($signingSecret) || $signingSecret === '')) {
            throw new RuntimeException(
                'The marketplace response included an invalid signing secret.'
                . $this->responseFailureDetails($response, $heartbeatUrl),
            );
        }

        if (($updates !== null && ! is_array($updates)) || ($advisories !== null && ! is_array($advisories))) {
            throw new RuntimeException(
                'The marketplace response did not include update and advisory lists.'
                . $this->responseFailureDetails($response, $heartbeatUrl),
            );
        }

        foreach (($updates ?? []) as $update) {
            if (! is_array($update)) {
                throw new RuntimeException(
                    'The marketplace response included an invalid update notice.'
                    . $this->responseFailureDetails($response, $heartbeatUrl),
                );
            }
        }

        foreach (($advisories ?? []) as $advisory) {
            if (! is_array($advisory)) {
                throw new RuntimeException(
                    'The marketplace response included an invalid advisory notice.'
                    . $this->responseFailureDetails($response, $heartbeatUrl),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string, mixed>
     */
    private function verifiedHeartbeatResponseData(array $responseData): array
    {
        $alerts = $this->listOfArrays($responseData['alerts'] ?? []);

        $signingSecret = $this->heartbeatSigningSecret($responseData);
        $verifiedAlerts = is_string($signingSecret) && $signingSecret !== ''
            ? array_values(array_filter(
                $alerts,
                fn (array $alert): bool => $this->alertSignatureIsValid($alert, $signingSecret),
            ))
            : [];

        $policy = is_array($responseData['policy'] ?? null) ? $responseData['policy'] : [];
        $policy['disable_extensions'] = array_values(array_filter(
            array_map(
                static fn (array $alert): ?string => (bool) ($alert['runtime_disabled'] ?? false) && is_string($alert['composer_name'] ?? null)
                    ? $alert['composer_name']
                    : null,
                $verifiedAlerts,
            ),
            static fn (?string $composerName): bool => $composerName !== null,
        ));

        return [
            ...$responseData,
            'alerts' => $verifiedAlerts,
            'policy' => $policy,
        ];
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    private function heartbeatSigningSecret(array $responseData): ?string
    {
        $instanceId = $responseData['instance_id'] ?? null;

        try {
            $query = MarketplaceInstance::query();

            if (is_string($instanceId) && $instanceId !== '') {
                $query->where('instance_id', $instanceId);
            }

            $marketplaceInstance = $query->latest('last_heartbeat_at')->first();
        } catch (Throwable) {
            $marketplaceInstance = null;
        }

        $signingSecret = $marketplaceInstance?->signing_secret_encrypted ?? config('capell-marketplace.marketplace.webhook_secret');

        return is_string($signingSecret) && $signingSecret !== '' ? $signingSecret : null;
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    private function alertSignatureIsValid(array $alert, string $signingSecret): bool
    {
        $signature = $alert['signature'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return $this->signer->verify($alert, $signingSecret, $signature);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOfArrays(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, is_array(...)));
    }

    private function responseFailureDetails(Response $response, string $heartbeatUrl): string
    {
        $contentType = $response->header('content-type');

        $details = ' Heartbeat URL: ' . $heartbeatUrl . '.';

        if ($contentType !== '') {
            $details .= ' The marketplace returned ' . $contentType . '.';
        }

        $detail = $response->json('error')
            ?? $response->json('message')
            ?? $this->htmlResponseSummary($response)
            ?? $response->body();

        if (! is_scalar($detail)) {
            return $details;
        }

        $detail = trim((string) $detail);

        if ($detail === '') {
            return $details;
        }

        return $details . ' Marketplace response: ' . str($detail)->limit(300);
    }

    private function htmlResponseSummary(Response $response): ?string
    {
        $contentType = strtolower($response->header('content-type'));
        $body = $response->body();

        if (! str_contains($contentType, 'html') && ! str_starts_with(ltrim($body), '<')) {
            return null;
        }

        if (preg_match('/<title[^>]*>(.*?)<\\/title>/is', $body, $matches) === 1) {
            $title = trim(html_entity_decode(strip_tags($matches[1])));

            if ($title !== '') {
                return 'The heartbeat URL returned HTML instead of JSON. Page title: ' . $title;
            }
        }

        return 'The heartbeat URL returned HTML instead of JSON.';
    }
}
