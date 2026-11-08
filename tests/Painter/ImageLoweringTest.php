<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;

it('gives the engine the radius at device size and the tint as it is', function () {
    $e = new SpyEngine;
    $image = new NativeFullFramebuffer(FormatSpec::rgba8(), 8, 8);
    $tint = Color::rgba(255, 128, 0, 0.5);

    $e->frame(fn () => (new EnginePainter($e, 2.0))->image($image, new Rect(2, 3, 10, 6), 4.0, $tint));
    [$source, $x, $y, $w, $h, $opacity, , $radii, $passed] = $e->argsOf('image')[0] + [7 => null, 8 => null];

    expect([$x, $y, $w, $h])->toBe([4.0, 6.0, 20.0, 12.0])
        ->and($opacity)->toBe(1.0)
        ->and($radii)->toBe([8.0, 8.0, 8.0, 8.0])
        ->and($passed)->toBe($tint);
});

it('draws a square image with no radii and no tint when it has none', function () {
    $e = new SpyEngine;
    $image = new NativeFullFramebuffer(FormatSpec::rgba8(), 8, 8);

    $e->frame(fn () => (new EnginePainter($e, 1.0))->image($image, new Rect(0, 0, 8, 8)));
    $args = $e->argsOf('image')[0];

    expect($args[7] ?? null)->toBeNull()
        ->and($args[8] ?? null)->toBeNull();
});
