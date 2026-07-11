<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Media;

use Capell\Core\Models\Page;
use Capell\Core\Tests\CoreTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Override;

/**
 * Abstract parity contract that every media backend must honour.
 *
 * Concrete subclasses implement activateBackend() to configure
 * the backend (set config values, bind classes, etc.). The method
 * is called via getEnvironmentSetUp() so the Laravel application
 * is already booted and the config() helper is available.
 */
abstract class MediaBackendTestCase extends CoreTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('public');
    }

    /**
     * Concrete subclasses configure the backend here.
     * The application is fully booted at this point.
     */
    abstract protected function activateBackend(): void;

    public function test_attach_from_upload_then_fetch_first_url(): void
    {
        $page = Page::factory()->createOne();

        $page->addMediaFromUploadedFile(UploadedFile::fake()->image('hero.jpg', 32, 32), 'image');

        $url = $page->getFirstMediaUrl('image');

        $this->assertNotEmpty($url);
    }

    public function test_get_media_returns_attached_item(): void
    {
        $page = Page::factory()->createOne();

        $page->addMediaFromUploadedFile(UploadedFile::fake()->image('a.jpg', 32, 32), 'image');

        $collection = $page->getMedia('image');

        $this->assertCount(1, $collection);
    }

    public function test_delete_media_clears_collection(): void
    {
        $page = Page::factory()->createOne();
        $page->addMediaFromUploadedFile(UploadedFile::fake()->image('x.jpg', 32, 32), 'image');

        $page->clearMediaCollection('image');

        $this->assertEmpty($page->getFirstMediaUrl('image'));
    }

    public function test_media_url_is_string(): void
    {
        $page = Page::factory()->createOne();
        $page->addMediaFromUploadedFile(UploadedFile::fake()->image('x.jpg', 32, 32), 'image');

        $url = $page->getFirstMediaUrl('image');

        $this->assertIsString($url);
    }

    /**
     * Called by Testbench during app boot — safe to call config() here.
     *
     * @param  Application  $app
     */
    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->activateBackend();
    }
}
