<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Layout;
use Capell\Core\Models\PublicRenderContractEvent;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Frontend\Contracts\FrontendContextReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * @method static void run(string $result, Response $response, ?string $reason = null, ?string $matchedMarker = null, ?string $category = null)
 */
final class RecordPublicRenderContractEventAction
{
    use AsFake;
    use AsObject;

    public function handle(
        string $result,
        Response $response,
        ?string $reason = null,
        ?string $matchedMarker = null,
        ?string $category = null,
    ): void {
        if (! $this->shouldRecord($result)) {
            return;
        }

        if (! $this->eventsTableExists()) {
            return;
        }

        try {
            PublicRenderContractEvent::query()->create([
                'result' => $result,
                'reason' => $this->redactedText($reason, 500),
                'matched_marker' => $this->redactedText($matchedMarker, 160),
                'package_name' => $this->packageName($matchedMarker),
                'source' => $category ?? 'public_render_contract',
                'url_hash' => $this->requestHash(full: true),
                'path_hash' => $this->requestHash(full: false),
                'response_hash' => $this->responseHash($response),
                'page_id' => $this->modelKey($this->context()?->page()),
                'layout_id' => $this->modelKey($this->context()?->layout()),
                'theme_id' => $this->modelKey($this->context()?->theme()),
                'context' => array_filter([
                    'status_code' => $response->getStatusCode(),
                    'content_type' => $this->redactedText((string) $response->headers->get('content-type', ''), 120),
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ]);
        } catch (Throwable) {
            return;
        }
    }

    private function shouldRecord(string $result): bool
    {
        if ($result === 'passed') {
            return $this->configuredBoolean('record_passed', false);
        }

        if ($result === 'failed') {
            return $this->configuredBoolean('record_failed', true);
        }

        return false;
    }

    private function configuredBoolean(string $key, bool $default): bool
    {
        $value = config('capell-frontend.public_render_contract_events.' . $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }

    private function eventsTableExists(): bool
    {
        try {
            return resolve(RuntimeSchemaState::class)->hasTable('capell_public_render_contract_events');
        } catch (Throwable) {
            return false;
        }
    }

    private function context(): ?FrontendContextReader
    {
        if (! app()->bound(FrontendContextReader::class)) {
            return null;
        }

        $context = resolve(FrontendContextReader::class);

        return $context instanceof FrontendContextReader ? $context : null;
    }

    private function modelKey(mixed $model): ?int
    {
        if (! $model instanceof Model && ! $model instanceof Pageable && ! $model instanceof Layout && ! $model instanceof Theme) {
            return null;
        }

        if (! method_exists($model, 'getKey')) {
            return null;
        }

        $key = $model->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    private function requestHash(bool $full): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        if (! $request instanceof Request) {
            return null;
        }

        $value = $full ? $request->fullUrl() : '/' . ltrim($request->path(), '/');

        return hash('xxh128', $value);
    }

    private function responseHash(Response $response): ?string
    {
        $content = $response->getContent();

        return is_string($content) ? hash('xxh128', $content) : null;
    }

    private function packageName(?string $matchedMarker): ?string
    {
        if (! is_string($matchedMarker) || $matchedMarker === '') {
            return null;
        }

        if (preg_match('#vendor/(?<vendor>[a-z0-9_.-]+)/(?<package>[a-z0-9_.-]+)#i', $matchedMarker, $matches) === 1) {
            return strtolower($matches['vendor'] . '/' . $matches['package']);
        }

        if (preg_match('#(?<vendor>[a-z0-9_.-]+)/(?<package>[a-z0-9_.-]+)#i', $matchedMarker, $matches) === 1) {
            return strtolower($matches['vendor'] . '/' . $matches['package']);
        }

        return null;
    }

    private function redactedText(?string $value, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $redacted = preg_replace('/signature=[^&\\s"\']+/i', 'signature=[redacted]', $value) ?? $value;
        $redacted = preg_replace('/token=[^&\\s"\']+/i', 'token=[redacted]', $redacted) ?? $redacted;

        return str($redacted)->limit($limit, '')->toString();
    }
}
