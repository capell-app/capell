<?php

declare(strict_types=1);

use Capell\Core\Exceptions\UploadContentRefusedException;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Support\Media\StorableUploadRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

beforeEach(function (): void {
    Storage::fake('public');
});

it('refuses active content from a writer that skips the admin upload form', function (string $fileName, string $contents): void {
    $page = Page::factory()->createOne();
    $publicFiles = Storage::disk('public')->allFiles();

    expect(fn () => $page->addMedia(UploadedFile::fake()->createWithContent($fileName, $contents))->toMediaCollection('images'))
        ->toThrow(UploadContentRefusedException::class);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe($publicFiles);
})->with([
    'an HTML page' => ['account.html', '<script>fetch("/admin/users")</script>'],
    'an XML document with a stylesheet' => ['feed.xml', '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="x.xsl"?><a/>'],
    'a file with no extension' => ['account', '<script>fetch("/admin/users")</script>'],
    'an HTML page behind a second extension' => ['account.html.vtt', '<script>fetch("/admin/users")</script>'],
    'a scripted SVG behind a second extension' => ['logo.svg.vtt', '<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("/admin/users")</script></svg>'],
    'a scripted SVG named as a raster image' => ['logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>fetch("/admin/users")</script></svg>'],
]);

it('refuses an SVG that hides its script from a naive check', function (string $svg): void {
    $page = Page::factory()->createOne();
    $publicFiles = Storage::disk('public')->allFiles();

    expect(fn () => $page->addMedia(UploadedFile::fake()->createWithContent('logo.svg', $svg))->toMediaCollection('images'))
        ->toThrow(UploadContentRefusedException::class);

    expect(Storage::disk('public')->allFiles())->toBe($publicFiles);
})->with([
    'script in the XHTML namespace' => ['<svg xmlns="http://www.w3.org/2000/svg"><h:script xmlns:h="http://www.w3.org/1999/xhtml">fetch("/admin/users")</h:script></svg>'],
    'embedded HTML' => ['<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="fetch(1)"/></body></foreignObject></svg>'],
    'a script URL split by whitespace and a character reference' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="java&#10;script&#58;fetch(1)"><text y="10">open</text></a></svg>'],
    'an animation that assigns a script URL' => ['<svg xmlns="http://www.w3.org/2000/svg"><a href="#"><set attributeName="href" to="javascript:fetch(1)"/><text y="10">open</text></a></svg>'],
    'an entity standing in for the script URL' => ['<!DOCTYPE svg [<!ENTITY go "javascript:fetch(1)">]><svg xmlns="http://www.w3.org/2000/svg"><a href="&go;"><text y="10">open</text></a></svg>'],
    'a stylesheet instruction' => ['<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="payload.xsl"?><svg xmlns="http://www.w3.org/2000/svg"/>'],
    'an image read from the server' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd" width="100" height="100"/></svg>'],
    'an image fetched from another host' => ['<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="http://169.254.169.254/latest/meta-data/" width="1" height="1"/></svg>'],
    'an HTML data URL' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="data:text/html;base64,PHNjcmlwdD4="/></svg>'],
    'markup that is not well formed' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>'],
]);

it('accepts a design-tool SVG that is only a drawing', function (): void {
    $svg = <<<'SVG_WRAP'
    <?xml version="1.0" encoding="UTF-8"?>
    <!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" viewBox="0 0 24 24" inkscape:version="1.3">
      <defs>
        <style>.mark{fill:url(#fade);stroke:#0f766e}</style>
        <linearGradient id="fade"><stop offset="0" stop-color="#0f766e"/><stop offset="1" stop-color="#134e4a"/></linearGradient>
        <path id="shape" d="M2 2h20v20H2z"/>
      </defs>
      <use xlink:href="#shape" class="mark"/>
      <image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>
      <animate attributeName="opacity" values="0;1" dur="1s"/>
    </svg>
    SVG_WRAP;

    // Judged by the form rule: storing it would hand the drawing to whichever
    // image converter the host has installed, which is not what this covers.
    $validator = Validator::make(
        ['logo' => UploadedFile::fake()->createWithContent('logo.svg', $svg)],
        ['logo' => [new StorableUploadRule]],
    );

    expect($validator->passes())->toBeTrue();
});

it('keeps document types refused when a host adds them to the allow-list', function (): void {
    config()->set('capell.media.uploads.allowed_extensions', ['html', 'glb']);
    $page = Page::factory()->createOne();

    expect(fn () => $page->addMedia(UploadedFile::fake()->createWithContent('account.html', '<script>fetch(1)</script>'))->toMediaCollection('images'))
        ->toThrow(UploadContentRefusedException::class);

    $model = $page->addMedia(UploadedFile::fake()->createWithContent('chair.glb', 'glTF'))->toMediaCollection('images');

    expect($model->file_name)->toEndWith('.glb');
});
