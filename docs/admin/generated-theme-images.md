# Generated Theme Images

![Capell Generated Theme Images screenshot](../images/generated/admin/theme-library-admin-flow.png)

If a theme card has no preview picture, here is what to know:

- You can upload your own preview image, and it always overrides the generated one.
- Generated preview images appear shortly after you save the theme.
- If a generated image never appears, ask your developer to check that the queue worker is running.

## How it works (developers)

Generated theme images give theme cards and theme selects a useful visual fallback when a theme has no manually uploaded preview image.

The feature is admin-only. It stores generated preview state on the theme's `admin` JSON payload and writes a PNG into the public storage disk. It does not change frontend rendering, theme CSS, cached page HTML, or static exports.

### When Generation Runs

Theme image generation is triggered from `Capell\Core\Observers\ThemeObserver` after a theme is saved.

On save, the observer:

1. Checks whether the theme has a manual admin image.
2. If a manual image exists, deletes any old generated image metadata and file.
3. If no manual image exists, calculates a generation signature from the theme name, key, colors, and admin icon.
4. If the current generated image is missing or stale, deletes the old generated file, marks generation as `pending`, and dispatches `GenerateThemeImageAction` as a queued job.

The generated image is removed before the queued job runs. Admin UI should therefore show no generated image while the new image is pending.

### Stored Metadata

Generated image state lives in `themes.admin`:

| Key                         | Meaning                                     |
| --------------------------- | ------------------------------------------- |
| `generated_image`           | Public storage path to the generated PNG    |
| `generated_image_signature` | Hash of preview-relevant theme data         |
| `generated_image_status`    | `pending`, `ready`, or `failed`             |
| `generated_image_error`     | Short failure message when generation fails |

Manual preview images still use the existing admin image/media fields. A manual image always wins over a generated image.

### Image Composition

`GenerateThemeImageAction` creates a `1200x1200` PNG using GD. The output is a square block image made from the theme's meta colors:

- `primary` fills the largest block.
- `secondary` fills the next block.
- the first remaining color fills the third block.

Only these three color tiers are used. If a theme has two colors, the image contains two fitted blocks. If it has one color, the image is a single solid square.

If the theme has an admin icon, the generator derives a short text mark from the icon name and draws it over the primary block with contrast-aware text color. This keeps the generated image simple and avoids persisting SVG or authoring markup.

### Display Rules

Admin preview surfaces resolve images in this order:

1. Manual media/admin image.
2. Ready generated image.
3. No image.

`pending` and `failed` generated images are not shown. Theme cards fall back to their normal title placeholder when no displayable image exists.

### Main Classes

| Class                                 | Responsibility                                             |
| ------------------------------------- | ---------------------------------------------------------- |
| `ThemeObserver`                       | Detects save events and queues/clears generated images     |
| `GenerateThemeImageAction`            | Queued job/action that renders and stores the PNG          |
| `InvalidateGeneratedThemeImageAction` | Deletes stale generated files and marks generation pending |
| `ClearGeneratedThemeImageAction`      | Removes generated state when a manual image exists         |
| `Theme::generatedImageSignature()`    | Builds the preview-relevant signature                      |
| `Theme::readyGeneratedImage()`        | Returns only displayable ready generated images            |
| `ThemeCardData` and `ThemeSelect`     | Prefer manual images, then ready generated images          |

### Operational Notes

- The queue worker must be running for generated images to appear after save.
- The public storage disk must be writable.
- GD must be available because the generator writes PNGs directly.
- A stale queued job will not overwrite a newer signature.
- Manual image changes do not leave old generated images behind; generated state is cleared when a manual image exists.
