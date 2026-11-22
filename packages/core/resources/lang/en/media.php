<?php

declare(strict_types=1);

return [
    'alignment' => [
        'bottom' => 'Bottom (full width)',
        'left' => 'Left (one third)',
        'right' => 'Right (one third)',
        'top' => 'Top (full width)',
    ],
    'image_source' => [
        'curator_media' => 'Curator media',
        'media' => 'Media library',
        'spatie_media' => 'Filament media',
        'upload' => 'Upload',
        'url' => 'URL',
    ],
    'image_source_preset' => [
        'all' => 'URL, upload, or media library',
        'media_only' => 'Media library only',
        'upload_media' => 'Upload or media library',
        'upload_only' => 'Upload only',
        'url_media' => 'URL or media library',
        'url_only' => 'URL only',
    ],
    'upload_refused' => [
        'active_svg' => '":file" was not stored because it contains script, embedded HTML, editor data or another active part, or is larger than 5 MB. Export it again as a plain SVG without editing data and upload that.',
        'type' => '":file" was not stored because this file type cannot be uploaded. Upload one of: :types.',
    ],
];
