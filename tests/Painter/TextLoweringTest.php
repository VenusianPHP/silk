<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\FontSpec;
use Silk\Painter\EnginePainter;
use Silk\Text\TextMeasurer;
use Surface\Contracts\Fonts\TrueTypeFont;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Surface\Tests\Support\Fakes\FakeFontDriver;

it('draws TrueType text through engine->text on the rounded baseline', function () {
    $e = new SpyEngine;
    $measurer = new TextMeasurer(new FakeFontDriver);

    $e->frame(fn () => (new EnginePainter($e, 2.0, null, $measurer))->text('Hi', 10.2, 20.3, Color::hex('#ffffff'), new FontSpec('sans-serif', 13.0)));

    [[$text, $x, $top, , $font]] = $e->argsOf('text');
    // FakeFontDriver's ascent is 0.75 of the size: the top is the rounded baseline 41 less 19.5
    expect($text)->toBe('Hi')
        ->and($font)->toBeInstanceOf(TrueTypeFont::class)
        ->and($font->size)->toBe(26.0)
        ->and($font->pattern)->toBe('sans-serif:weight=regular')
        ->and([$x, $top])->toBe([20.0, 41.0 - 19.5]);
});

it('draws nothing without a measurer or font, for empty text, or in an empty clip', function () {
    $e = new SpyEngine;
    $style = new FontSpec;

    $e->frame(function () use ($e, $style) {
        (new EnginePainter($e, 1.0))->text('Hi', 0, 10, Color::hex('#fff'), $style);
        $p = new EnginePainter($e, 1.0, null, new TextMeasurer(new FakeFontDriver));
        $p->text('', 0, 10, Color::hex('#fff'), $style);
        $p->pushClip(new Rect(0, 0, 0, 0))->text('Hi', 0, 10, Color::hex('#fff'), $style)->popClip();
    });

    expect($e->methods())->toBe(['clip', 'clip']);
});
