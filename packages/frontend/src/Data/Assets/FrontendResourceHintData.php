<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\FetchPriority;
use Capell\Frontend\Enums\FrontendResourceHintAs;
use Capell\Frontend\Enums\FrontendResourceHintKind;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class FrontendResourceHintData extends Data
{
    public function __construct(
        public readonly FrontendResourceHintKind $kind,
        public readonly string $href,
        public readonly ?FrontendResourceHintAs $as = null,
        public readonly ?string $mimeType = null,
        public readonly ?CrossOrigin $crossOrigin = null,
        public readonly ?FetchPriority $fetchPriority = null,
    ) {
        if ($href === '' || preg_match('/[\x00-\x20]/', $href) === 1) {
            throw new InvalidArgumentException('Frontend resource hint href cannot be blank or contain control characters.');
        }

        if (str_starts_with($href, '//') || (preg_match('/^[a-z][a-z0-9+.-]*:/i', $href) === 1 && preg_match('#^https?://#i', $href) !== 1)) {
            throw new InvalidArgumentException('Frontend resource hints accept only local paths or absolute HTTP(S) URLs.');
        }

        if (preg_match('#^https?://#i', $href) === 1) {
            $parts = parse_url($href);

            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('External frontend resource hints cannot contain credentials or fragments.');
            }
        }

        if (in_array($kind, [FrontendResourceHintKind::Preconnect, FrontendResourceHintKind::DnsPrefetch], true) && ($as !== null || $mimeType !== null || $fetchPriority !== null)) {
            throw new InvalidArgumentException('Connection hints cannot declare as, MIME type, or fetch priority attributes.');
        }

        if ($kind === FrontendResourceHintKind::ModulePreload && $as !== null && $as !== FrontendResourceHintAs::Script) {
            throw new InvalidArgumentException('Module preload hints may only use the script destination.');
        }
    }
}
