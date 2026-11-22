<?php

declare(strict_types=1);

namespace Capell\Core\Support\Media;

use Capell\Core\Exceptions\UploadContentRefusedException;
use Closure;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The one rule for what an uploaded file may be before it is stored where a web
 * server can serve it.
 *
 * Stored uploads are served from the site origin, beside the admin panel, and a
 * static server chooses the Content-Type from the file extension. A file that a
 * browser renders as a document (HTML, XML, script-bearing SVG) would therefore
 * run with the session of whoever opens its URL. Only extensions on the
 * allow-list are stored, and an SVG must be a static drawing.
 */
final class UploadContentPolicy
{
    /** @var list<string> */
    public const array DEFAULT_ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic', 'heif',
        'mp4', 'm4v', 'webm', 'mov', 'ogv',
        'mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv',
        'vtt', 'srt',
        'woff', 'woff2', 'ttf', 'otf',
        'zip',
    ];

    /**
     * Extensions a browser treats as a document or script whatever a host adds
     * to the allow-list. SVG is absent because it is inspected instead.
     *
     * @var list<string>
     */
    private const array NEVER_ALLOWED_EXTENSIONS = [
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'mht', 'mhtml', 'hta', 'htc',
        'xml', 'xsl', 'xslt', 'svgz', 'js', 'mjs', 'cjs', 'swf',
    ];

    public function __construct(private readonly SvgContentInspector $svg) {}

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        $configured = config('capell.media.uploads.allowed_extensions');
        $extensions = is_array($configured) ? $configured : self::DEFAULT_ALLOWED_EXTENSIONS;

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $extension): string => is_string($extension) ? strtolower(ltrim(trim($extension), '.')) : '', $extensions),
            static fn (string $extension): bool => $extension !== '' && ! in_array($extension, self::NEVER_ALLOWED_EXTENSIONS, true),
        )));
    }

    /**
     * @param  string  $fileName  The name the file will be stored or served under.
     * @param  string|null  $path  Readable local path of the content. An SVG without one is refused.
     *
     * @throws UploadContentRefusedException
     */
    public function assertStorable(string $fileName, ?string $path = null): void
    {
        $extension = $this->assertAllowedExtension($fileName);

        if ($extension !== 'svg' && ($path === null || ! $this->looksLikeSvg($path))) {
            return;
        }

        // An SVG whose content cannot be read cannot be shown to be static.
        $contents = $path !== null && is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false || ! $this->svg->isStatic($contents)) {
            throw UploadContentRefusedException::activeSvg(basename($fileName));
        }
    }

    /**
     * For content that has no local path, such as a file on a remote disk.
     *
     * @throws UploadContentRefusedException
     */
    public function assertStorableContents(string $fileName, string $contents): void
    {
        if ($this->assertAllowedExtension($fileName) === 'svg' && ! $this->svg->isStatic($contents)) {
            throw UploadContentRefusedException::activeSvg(basename($fileName));
        }
    }

    /**
     * Judges an upload under the name its sender gave it. A temporary upload held
     * on a remote disk has no local path, so its content is read from that disk.
     */
    public function refusalForUpload(UploadedFile $upload): ?UploadContentRefusedException
    {
        $fileName = $upload->getClientOriginalName();
        $path = $upload->getRealPath();

        try {
            if (is_string($path) && is_file($path)) {
                $this->assertStorable($fileName, $path);
            } else {
                $this->assertStorableContents($fileName, (string) ($upload instanceof TemporaryUploadedFile ? $upload->get() : $upload->getContent()));
            }
        } catch (UploadContentRefusedException $uploadContentRefusedException) {
            return $uploadContentRefusedException;
        }

        return null;
    }

    /**
     * Judges a file that is already stored. The content is read only when the
     * name makes it an SVG, so a large library is not downloaded to be checked.
     *
     * @param  Closure(): ?string  $readContents
     */
    public function refusalForStored(string $fileName, Closure $readContents): ?UploadContentRefusedException
    {
        try {
            if ($this->assertAllowedExtension($fileName) === 'svg' && ! $this->svg->isStatic((string) $readContents())) {
                throw UploadContentRefusedException::activeSvg(basename($fileName));
            }
        } catch (UploadContentRefusedException $uploadContentRefusedException) {
            return $uploadContentRefusedException;
        }

        return null;
    }

    public function refusal(string $fileName, ?string $path = null): ?UploadContentRefusedException
    {
        try {
            $this->assertStorable($fileName, $path);
        } catch (UploadContentRefusedException $uploadContentRefusedException) {
            return $uploadContentRefusedException;
        }

        return null;
    }

    /**
     * @return string The extension the content must be judged as.
     *
     * @throws UploadContentRefusedException
     */
    private function assertAllowedExtension(string $fileName): string
    {
        $segments = array_slice(explode('.', strtolower(basename($fileName))), 1);
        $extension = (string) end($segments);
        $allowed = $this->allowedExtensions();

        // Apache reads every extension in a name and lets an earlier one set the type
        // when it does not recognise the last, so `page.html.vtt` can be served as HTML.
        if (! in_array($extension, $allowed, true) || array_intersect($segments, self::NEVER_ALLOWED_EXTENSIONS) !== []) {
            throw UploadContentRefusedException::type(basename($fileName), $allowed);
        }

        // The same rule makes `logo.svg.vtt` an SVG, so it is inspected as one.
        return in_array('svg', $segments, true) ? 'svg' : $extension;
    }

    /**
     * An SVG stored under an image extension is still an SVG to anything that
     * sniffs content, so it gets the same inspection.
     */
    private function looksLikeSvg(string $path): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $mime = mime_content_type($path);

        return is_string($mime) && str_contains(strtolower($mime), 'svg');
    }
}
