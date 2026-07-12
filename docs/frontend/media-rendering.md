# Media Rendering

The frontend media component renders Capell media records with responsive image URLs, width and height attributes, and localized accessibility metadata.

## Localized Alt Text

When `<x-capell::media>` receives a `Capell\Core\Models\Media` record and no explicit `alt` prop, it resolves metadata for the current frontend language:

```blade
<x-capell::media :media="$page->image" />
```

The component reads `translations.meta.alt` for the active language. If no localized alt text exists, it falls back to the media name.

Pass `alt` explicitly when a component needs context-specific alt text:

```blade
<x-capell::media
    :media="$card->image"
    :alt="$card->image_alt"
/>
```

## Decorative Images

If the localized metadata marks the image as decorative, the component renders `alt=""`. This keeps purely decorative imagery out of the screen-reader reading order while preserving the image visually.

## Captions And Credits

Captions and credits are available from the media model:

```blade
@php($metadata = $media->localizedMetadata(Frontend::language()))

<figure>
    <x-capell::media :media="$media" />

    @if ($metadata->caption)
        <figcaption>{{ $metadata->caption }}</figcaption>
    @endif
</figure>
```

## Crops

Rendered URLs use generated Spatie conversions when available. Curator remains responsible for its own crop output when configured as the media backend; the frontend component consumes the resulting media URLs rather than duplicating crop logic.

## Next

- [Frontend](index.md)
- [Frontend guide](guide.md)
- [Public HTML safety contract](public-html-safety.md)
