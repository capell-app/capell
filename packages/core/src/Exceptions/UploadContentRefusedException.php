<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded;

/**
 * A file was refused before it reached media storage because a browser could run
 * it on the site origin: its type is not on the upload allow-list, or it is an
 * SVG that is not a static drawing.
 *
 * The message is written for the person uploading and is safe to show them.
 */
final class UploadContentRefusedException extends FileCannotBeAdded
{
    public const string REASON_TYPE = 'type';

    public const string REASON_ACTIVE_SVG = 'active_svg';

    private function __construct(string $message, public readonly string $reason, public readonly string $fileName)
    {
        parent::__construct($message);
    }

    /** @param list<string> $allowedExtensions */
    public static function type(string $fileName, array $allowedExtensions): self
    {
        return new self((string) __('capell-core::media.upload_refused.type', [
            'file' => $fileName,
            'types' => implode(', ', $allowedExtensions),
        ]), self::REASON_TYPE, $fileName);
    }

    public static function activeSvg(string $fileName): self
    {
        return new self((string) __('capell-core::media.upload_refused.active_svg', ['file' => $fileName]), self::REASON_ACTIVE_SVG, $fileName);
    }
}
