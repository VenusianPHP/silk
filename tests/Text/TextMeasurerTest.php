<?php

use Silk\Painter\EnginePainter;
use Silk\Text\TextMeasurer;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Surface\Tests\Support\Fakes\FakeFontDriver;
use Silk\Contracts\Look\FontSpec;


it('measures the shaped run at scale 1', function () {
    skipWithoutFontExts();
    $driver = freshDriver();
    $style = new FontSpec('sans-serif', 13.0);
    $run = $driver->shape(TextMeasurer::pattern($style), 13.0, 'Zoom out');

    $size = (new TextMeasurer($driver))->measure('Zoom out', $style);

    expect($size->w)->toBe($run->advance)->and($size->w)->toBeGreaterThan(30.0)
        ->and($size->h)->toBe($run->lineHeight);
});

it('gives the face metrics, and an empty string width 0 with the same line height', function () {
    skipWithoutFontExts();
    $m = new TextMeasurer(freshDriver());
    $style = new FontSpec('sans-serif', 13.0);

    $metrics = $m->metrics($style);
    $empty = $m->measure('', $style);

    expect($metrics['ascent'])->toBeGreaterThan(5.0)
        ->and($metrics['descent'])->toBeGreaterThan(0.0)
        // the face's own line height; it may be less than ascent + descent
        ->and($metrics['lineHeight'])->toBeGreaterThan(10.0)
        ->and($empty->w)->toBe(0.0)
        ->and($empty->h)->toBe($metrics['lineHeight']);
});

it('falls back to the classic GFXFont without a shaper', function () {
    $m = new TextMeasurer(null);

    expect($m->measure('abc', new FontSpec)->w)->toBe(18.0)
        ->and($m->driver())->toBeNull()
        ->and($m->font(new FontSpec))->toBeNull();
});

it('builds a fontconfig pattern from a FontSpec', function () {
    expect(TextMeasurer::pattern(new FontSpec('Inter', 13.0, 600, true)))->toBe('Inter:weight=demibold:slant=italic')
        ->and(TextMeasurer::pattern(new FontSpec('Inter', 13.0, 450)))->toBe('Inter:weight=medium')
        ->and(TextMeasurer::pattern(new FontSpec('Inter', 13.0, 350)))->toBe('Inter:weight=regular')
        ->and(TextMeasurer::pattern(new FontSpec('Inter', 13.0, 1200)))->toBe('Inter:weight=black');
});

it('keeps one TrueType font per FontSpec, at its logical size', function () {
    $m = new TextMeasurer(new FakeFontDriver);
    $font = $m->font(new FontSpec('Inter', 13.0, 700));

    expect($font->pattern)->toBe('Inter:weight=bold')
        ->and($font->size)->toBe(13.0)
        ->and($m->font(new FontSpec('Inter', 13.0, 700)))->toBe($font)
        ->and($m->font(new FontSpec('Inter', 14.0, 700)))->not->toBe($font)
        ->and($m->measure('Hi', new FontSpec('Inter', 13.0))->w)->toBe(13.0);
});

it('falls back to the classic face without a driver (Review Focus 1)', function () {
    $m = new TextMeasurer(null);
    $e = new SpyEngine;

    $e->frame(fn () => (new EnginePainter($e, 1.0, new Surface\Fonts\ClassicFont, $m))->text('Hi', 0, 10, Color::hex('#fff'), new FontSpec));
    [[$text, , , , $font]] = $e->argsOf('text');

    expect($text)->toBe('Hi')->and($font)->toBeInstanceOf(Surface\Fonts\ClassicFont::class);
});

it('draws an unknown family as fontconfig matches it (Review Focus 3)', function () {
    skipWithoutFontExts();

    expect((new TextMeasurer(freshDriver()))->measure('Hello', new FontSpec('NoSuchFamily', 13.0))->w)->toBeGreaterThan(0.0);
});
