<?php

declare(strict_types=1);

use Capell\Core\Actions\Media\ResolveResponsiveImageAction;
use Capell\Core\Data\ResponsiveImageData;
use Illuminate\Support\Facades\Storage;

function responsiveTestImage(int $width, int $height, string $format = 'jpeg'): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));

    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        default => imagejpeg($image),
    };

    return (string) ob_get_clean();
}

beforeEach(function (): void {
    Storage::fake('public');
});

it('describes a stored image and writes smaller derivatives for its srcset', function (): void {
    Storage::disk('public')->put('widgets/slides/hero.jpg', responsiveTestImage(2000, 1000));

    $image = ResolveResponsiveImageAction::run('widgets/slides/hero.jpg');

    expect($image)->toBeInstanceOf(ResponsiveImageData::class)
        ->and($image->width)->toBe(2000)
        ->and($image->height)->toBe(1000)
        ->and($image->src)->toEndWith('widgets/slides/hero.jpg')
        ->and($image->srcset)->toContain(' 480w')->toContain(' 768w')->toContain(' 1200w')->toContain(' 1600w')
        ->and($image->srcset)->toEndWith('hero.jpg 2000w');

    $files = Storage::disk('public')->files(ResolveResponsiveImageAction::DERIVATIVE_DIRECTORY);
    expect($files)->toHaveCount(4);

    $size = getimagesizefromstring((string) Storage::disk('public')->get($files[0]));
    expect($size)->not->toBeFalse();
    assert(is_array($size));
    expect([$size[0], $size[1]])->toBe([1200, 600]);
});

it('does not rewrite derivatives that already exist, and makes new ones when the image changes', function (): void {
    $disk = Storage::disk('public');
    $disk->put('a.png', responsiveTestImage(900, 600, 'png'));

    $first = ResolveResponsiveImageAction::run('a.png');
    $firstFiles = $disk->files(ResolveResponsiveImageAction::DERIVATIVE_DIRECTORY);

    expect(ResolveResponsiveImageAction::run('a.png')->srcset)->toBe($first->srcset)
        ->and($disk->files(ResolveResponsiveImageAction::DERIVATIVE_DIRECTORY))->toBe($firstFiles);

    $disk->put('a.png', responsiveTestImage(1800, 1200, 'png'));

    $changed = ResolveResponsiveImageAction::run('a.png');

    expect($changed->width)->toBe(1800)
        ->and($changed->srcset)->not->toBe($first->srcset);
});

it('never upscales: an image no wider than the smallest derivative has no srcset', function (): void {
    Storage::disk('public')->put('small.webp', responsiveTestImage(400, 300, 'webp'));

    $image = ResolveResponsiveImageAction::run('small.webp');

    expect($image->width)->toBe(400)
        ->and($image->height)->toBe(300)
        ->and($image->srcset)->toBeNull()
        ->and(Storage::disk('public')->files(ResolveResponsiveImageAction::DERIVATIVE_DIRECTORY))->toBe([]);
});

it('returns null for a missing path, a non-image file and unsupported or hostile paths', function (string $path): void {
    Storage::disk('public')->put('notes.txt', 'not an image');
    Storage::disk('public')->put('page.html', '<html></html>');
    Storage::disk('public')->put('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

    expect(ResolveResponsiveImageAction::run($path))->toBeNull();
})->with([
    'missing' => 'widgets/missing.jpg',
    'text file' => 'notes.txt',
    'html file' => 'page.html',
    'svg' => 'vector.svg',
    'empty' => '',
    'traversal' => '../.env',
    'nested traversal' => 'widgets/../../.env',
    'null byte' => "a.jpg\0.txt",
]);

it('returns null for a corrupt image that claims to be a jpeg', function (): void {
    Storage::disk('public')->put('broken.jpg', "\xFF\xD8\xFF\xE0" . random_bytes(64));

    expect(ResolveResponsiveImageAction::run('broken.jpg'))->toBeNull();
});

it('exposes only the public image description, with no authoring state or filesystem paths', function (): void {
    Storage::disk('public')->put('widgets/hero.jpg', responsiveTestImage(1600, 800));

    $array = ResolveResponsiveImageAction::run('widgets/hero.jpg')->toArray();

    expect(array_keys($array))->toBe(['src', 'width', 'height', 'srcset']);

    $serialised = json_encode($array, JSON_THROW_ON_ERROR);

    expect($serialised)->not->toContain(Storage::disk('public')->path(''))
        ->not->toContain('signature')
        ->not->toContain('expires')
        ->not->toContain('token')
        ->not->toContain('?');
});

it('keeps transparency in png derivatives', function (): void {
    $image = imagecreatetruecolor(1000, 500);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 10, 20, 30, 127));
    ob_start();
    imagepng($image);
    Storage::disk('public')->put('logo.png', (string) ob_get_clean());

    ResolveResponsiveImageAction::run('logo.png');

    $derivative = imagecreatefromstring(Storage::disk('public')->get(Storage::disk('public')->files(ResolveResponsiveImageAction::DERIVATIVE_DIRECTORY)[0]));

    expect((imagecolorat($derivative, 5, 5) >> 24) & 0x7F)->toBe(127);
});
