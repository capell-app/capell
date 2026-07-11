<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

it('escapes asset tile title and summary content', function (): void {
    $title = '<img src=x onerror=alert(1)>Unsafe title<script>alert(2)</script>';
    $summary = '<svg onload=alert(3)>Unsafe summary & copy</svg>';

    $html = Blade::render(
        '<x-capell::asset.tile :title="$title" :summary="$summary" />',
        [
            'title' => $title,
            'summary' => $summary,
        ],
    );

    expect($html)
        ->toContain(e($title))
        ->toContain(e($summary))
        ->not->toContain($title)
        ->not->toContain($summary)
        ->not->toContain('<script>alert(2)</script>')
        ->not->toContain('<svg onload=alert(3)>');
});

it('escapes asset index title content', function (): void {
    $title = '<img src=x onerror=alert(1)>Unsafe index title<script>alert(2)</script>';

    $html = Blade::render(
        '<x-capell::asset.index :title="$title" />',
        [
            'title' => $title,
        ],
    );

    expect($html)
        ->toContain(e($title))
        ->not->toContain($title)
        ->not->toContain('<script>alert(2)</script>');
});
