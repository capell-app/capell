# Widget State

![Capell Widget State screenshot](../images/generated/admin/site-health-page.png)

A widget instance carries its own content props plus Capell runtime metadata. This page covers the two instance-state layers, how presentation settings resolve, and how to choose a loading strategy.

## Instance State

Widget instance data has two layers:

| Layer             | Purpose                                                | Public component receives it? |
| ----------------- | ------------------------------------------------------ | ----------------------------- |
| `data.*`          | The widget's own content props                         | Yes                           |
| `data.__capell.*` | Capell runtime, presentation, and interaction metadata | No                            |

For example:

```php
[
    'type' => 'video-player',
    'data' => [
        'title' => 'Product walkthrough',
        'video_url' => 'https://example.com/video.mp4',
        '__capell' => [
            'presentation' => [
                'loading_strategy' => 'visible',
            ],
            'interactions' => [
                [
                    'label' => 'Open transcript',
                    'target_type' => 'widget',
                    'behavior' => 'modal',
                    'target_widget' => [
                        ['type' => 'content', 'data' => ['content' => '<p>Transcript...</p>']],
                    ],
                ],
            ],
        ],
    ],
]
```

The public renderer strips `data.__capell` before passing props to the widget component. A video widget can receive `title` and `video_url`; it must not receive its presentation settings, nested target widgets, editor metadata, or interaction internals.

## Presentation Defaults And Overrides

Widget definitions can provide type defaults through `defaultPresentationSettings`. Editors can override those settings on one widget instance under `data.__capell.presentation`.

Resolution order is:

1. instance override in `data.__capell.presentation`;
2. widget definition default;
3. presentation preset default;
4. system default.

The system default keeps existing content server-rendered. Only opt a widget or block into lazy behaviour when it should genuinely wait for visibility, idle time, or visitor interaction.

## Choosing Loading Behaviour

| Loading strategy | Use when                                                           |
| ---------------- | ------------------------------------------------------------------ |
| `eager`          | The widget is visible and needed for the first render.             |
| `visible`        | The widget can wait until it enters the viewport.                  |
| `interaction`    | The widget is only needed after a click, focus, or similar action. |
| `idle`           | The widget is useful soon, but not critical to initial rendering.  |

For interaction targets, prefer `interaction` resources unless the target also appears server-rendered elsewhere on the page.

## Next

- [Frontend widgets](widgets.md)
- [Widget registration](widget-registration.md)
- [Widget and fragment targets](widget-targets.md)
