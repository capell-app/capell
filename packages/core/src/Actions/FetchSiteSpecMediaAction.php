<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Data\SiteSpec\CapellSiteSpecMediaData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Support\CapellSiteSpecConstraints;
use Capell\Core\Support\SiteSpec\SiteSpecMediaDownload;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

final class FetchSiteSpecMediaAction
{
    use AsFake;
    use AsObject;

    /** @var array<string, string> */
    private const array ALLOWED_IMAGE_MIME_TYPES = [
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @return list<SiteSpecMediaDownload>
     */
    public function handle(CapellSiteSpecMediaData $media): array
    {
        if (! $media->hasRemoteAssets()) {
            return [];
        }

        throw_unless(is_string($media->sourceUrl) && $media->sourceUrl !== '', RuntimeException::class, 'A source URL is required when importing remote site spec media.');

        $sourceOrigin = $this->origin($media->sourceUrl);
        $downloads = [];
        $totalBytes = 0;

        try {
            if (is_string($media->logo) && $media->logo !== '') {
                $downloads[] = $this->download(
                    url: $media->logo,
                    sourceOrigin: $sourceOrigin,
                    collection: MediaCollectionEnum::Logo,
                    totalBytes: $totalBytes,
                );
            }

            foreach ($media->images as $pageSlug => $url) {
                throw_unless(is_string($pageSlug) && is_string($url), RuntimeException::class, 'Site spec images must map page slugs to remote URLs.');

                $downloads[] = $this->download(
                    url: $url,
                    sourceOrigin: $sourceOrigin,
                    collection: MediaCollectionEnum::Image,
                    totalBytes: $totalBytes,
                    pageSlug: $pageSlug,
                );
            }

            return $downloads;
        } catch (Throwable $throwable) {
            $this->deleteDownloads($downloads);

            throw $throwable;
        }
    }

    /** @param list<SiteSpecMediaDownload> $downloads */
    public function deleteDownloads(array $downloads): void
    {
        foreach ($downloads as $download) {
            if (is_file($download->path)) {
                unlink($download->path);
            }
        }
    }

    private function download(
        string $url,
        string $sourceOrigin,
        MediaCollectionEnum $collection,
        int &$totalBytes,
        ?string $pageSlug = null,
    ): SiteSpecMediaDownload {
        throw_if(strlen($url) > CapellSiteSpecConstraints::MAX_REMOTE_URL_LENGTH, RuntimeException::class, 'A site spec media URL exceeds the maximum length.');
        $safeUrl = $this->safeUrlLabel($url);

        throw_unless($this->origin($url) === $sourceOrigin, RuntimeException::class, sprintf('Remote media URL [%s] is outside the declared source origin.', $safeUrl));

        $urlParts = $this->urlParts($url);
        $addresses = $this->resolvePublicAddresses($urlParts['host']);
        $remainingBytes = CapellSiteSpecConstraints::MAX_MEDIA_TOTAL_BYTES - $totalBytes;

        throw_if($remainingBytes <= 0, RuntimeException::class, 'The site spec media total exceeds the allowed download budget.');

        $maxBytes = min(CapellSiteSpecConstraints::MAX_MEDIA_FILE_BYTES, $remainingBytes);
        try {
            $response = $this->request($url, $urlParts['host'], $urlParts['port'], $addresses[0]);
        } catch (Throwable $throwable) {
            throw new RuntimeException(sprintf('Remote media URL [%s] could not be fetched.', $safeUrl), $throwable->getCode(), previous: $throwable);
        }

        throw_unless($response->successful(), RuntimeException::class, sprintf('Remote media URL [%s] returned HTTP %d.', $safeUrl, $response->status()));

        $declaredLength = $response->header('Content-Length');
        if (is_string($declaredLength) && ctype_digit($declaredLength)) {
            throw_if((int) $declaredLength > $maxBytes, RuntimeException::class, sprintf('Remote media URL [%s] exceeds the allowed download size.', $safeUrl));
        }

        $declaredMimeType = $this->normalizedMimeType((string) $response->header('Content-Type'));
        if ($declaredMimeType !== '') {
            throw_unless(isset(self::ALLOWED_IMAGE_MIME_TYPES[$declaredMimeType]), RuntimeException::class, sprintf('Remote media URL [%s] is not an allowed image type.', $safeUrl));
        }

        $path = $this->temporaryPath();

        try {
            $bytes = $this->writeResponse($response, $path, $maxBytes);
            $detectedMimeType = $this->detectedMimeType($path);

            throw_unless(isset(self::ALLOWED_IMAGE_MIME_TYPES[$detectedMimeType]), RuntimeException::class, sprintf('Remote media URL [%s] did not contain an allowed image.', $safeUrl));
            throw_if($declaredMimeType !== '' && $declaredMimeType !== $detectedMimeType, RuntimeException::class, sprintf('Remote media URL [%s] returned mismatched image content.', $safeUrl));

            $totalBytes += $bytes;
            $sourceHash = hash('sha256', $url);

            return new SiteSpecMediaDownload(
                path: $path,
                fileName: sprintf('site-spec-%s.%s', substr($sourceHash, 0, 20), self::ALLOWED_IMAGE_MIME_TYPES[$detectedMimeType]),
                sourceOrigin: $sourceOrigin,
                sourceHash: $sourceHash,
                collection: $collection,
                pageSlug: $pageSlug,
            );
        } catch (Throwable $throwable) {
            if (is_file($path)) {
                unlink($path);
            }

            throw $throwable;
        }
    }

    private function request(string $url, string $host, int $port, string $address): Response
    {
        throw_unless(defined('CURLOPT_RESOLVE'), RuntimeException::class, 'The cURL extension is required for SSRF-safe site spec media imports.');

        /** @var array<string, mixed> $options */
        $options = [
            'allow_redirects' => false,
            'stream' => true,
        ];

        $curlResolveOption = constant('CURLOPT_RESOLVE');
        $resolvedAddress = str_contains($address, ':') ? '[' . $address . ']' : $address;
        $options['curl'] = [
            $curlResolveOption => [sprintf('%s:%d:%s', $host, $port, $resolvedAddress)],
        ];

        return Http::accept('image/*')
            ->withUserAgent('Capell-SiteSpec-Importer/1.0')
            ->connectTimeout(5)
            ->timeout(15)
            ->withOptions($options)
            ->get($url);
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'capell-site-spec-media-');

        throw_unless(is_string($path), RuntimeException::class, 'Unable to create a temporary file for site spec media.');

        if (! chmod($path, 0600)) {
            unlink($path);

            throw new RuntimeException('Unable to secure a temporary site spec media file.');
        }

        return $path;
    }

    private function writeResponse(Response $response, string $path, int $maxBytes): int
    {
        $stream = $response->toPsrResponse()->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $handle = fopen($path, 'wb');

        throw_unless($handle !== false, RuntimeException::class, 'Unable to open a temporary site spec media file.');

        $bytes = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(8192);

                if ($chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);
                throw_if($bytes > $maxBytes, RuntimeException::class, 'A remote site spec media file exceeds the allowed download size.');

                $remaining = $chunk;
                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);
                    throw_if($written === false || $written === 0, RuntimeException::class, 'Unable to write a temporary site spec media file.');
                    $remaining = substr($remaining, $written);
                }
            }
        } finally {
            fclose($handle);
        }

        throw_if($bytes === 0, RuntimeException::class, 'A remote site spec media file was empty.');

        return $bytes;
    }

    private function detectedMimeType(string $path): string
    {
        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        throw_unless($fileInfo !== false, RuntimeException::class, 'Unable to inspect downloaded site spec media.');

        try {
            $mimeType = finfo_file($fileInfo, $path);
        } finally {
            finfo_close($fileInfo);
        }

        return is_string($mimeType) ? $this->normalizedMimeType($mimeType) : '';
    }

    private function normalizedMimeType(string $mimeType): string
    {
        return strtolower(trim(explode(';', $mimeType, 2)[0]));
    }

    private function origin(string $url): string
    {
        $parts = $this->urlParts($url);
        $port = $parts['port'] === 443 ? '' : ':' . $parts['port'];

        return sprintf('https://%s%s', $parts['host'], $port);
    }

    /** @return array{host: string, port: int} */
    private function urlParts(string $url): array
    {
        throw_if(strlen($url) > CapellSiteSpecConstraints::MAX_REMOTE_URL_LENGTH, RuntimeException::class, 'A site spec media URL exceeds the maximum length.');
        throw_unless(filter_var($url, FILTER_VALIDATE_URL) !== false, RuntimeException::class, sprintf('Remote media URL [%s] is invalid.', $this->safeUrlLabel($url)));

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';

        throw_unless($scheme === 'https' && $host !== '', RuntimeException::class, 'Site spec media URLs must use HTTPS.');
        throw_if(isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']), RuntimeException::class, 'Site spec media URLs must not contain credentials or fragments.');

        $port = $parts['port'] ?? 443;
        throw_unless($port >= 1 && $port <= 65535, RuntimeException::class, 'A site spec media URL contains an invalid port.');

        return ['host' => $host, 'port' => $port];
    }

    /** @return non-empty-list<string> */
    private function resolvePublicAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            $addresses = [];

            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = $record['ip'] ?? $record['ipv6'] ?? null;

                    if (is_string($address)) {
                        $addresses[] = $address;
                    }
                }
            }

            if ($addresses === []) {
                $ipv4Addresses = @gethostbynamel($host);
                $addresses = is_array($ipv4Addresses) ? $ipv4Addresses : [];
            }
        }

        $addresses = array_values(array_unique($addresses));

        throw_if($addresses === [], RuntimeException::class, sprintf('Remote media host [%s] could not be resolved.', $host));

        foreach ($addresses as $address) {
            throw_unless($this->isPublicAddress($address), RuntimeException::class, sprintf('Remote media host [%s] resolves to a non-public address.', $host));
        }

        /** @var non-empty-list<string> $addresses */
        return $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    private function safeUrlLabel(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! is_string($parts['host'] ?? null)) {
            return 'invalid-url';
        }

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }
}
