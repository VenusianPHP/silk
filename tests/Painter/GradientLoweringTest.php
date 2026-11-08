<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\GradientSpread;
use Surface\NutsAndBolts\LinearGradient;
use Surface\NutsAndBolts\RadialGradient;
use Venusian\Silk\Tests\Support\SpyEngine;

it('fills the rounded rect with the gradient moved to device pixels', function () {
    $e = new SpyEngine;
    $black = Color::rgb(0, 0, 0);
    $white = Color::rgb(255, 255, 255);

    $e->frame(fn () => (new EnginePainter($e, 2.0))->gradient(new Rect(5, 10, 20, 8), new LinearGradient(5, 10, 25, 10, [[0.0, $black], [1.0, $white]], GradientSpread::REFLECT), 3.0));
    [$path, $paint] = $e->argsOf('fill')[0];

    expect($paint)->toEqual(new LinearGradient(10, 20, 50, 20, [[0.0, $black], [1.0, $white]], GradientSpread::REFLECT))
        ->and($path->ops()[0])->toBe(['M', 16.0, 20.0])
        ->and(array_column($path->ops(), 0))->toBe(['M', 'L', 'C', 'L', 'C', 'L', 'C', 'L', 'C', 'Z']);
});

it('scales a radial gradient and fades its stops by the opacity pushed', function () {
    $e = new SpyEngine;
    $red = Color::rgb(255, 0, 0);

    $e->frame(function () use ($e, $red) {
        $p = new EnginePainter($e, 2.0);
        $p->pushOpacity(0.5)->gradient(new Rect(0, 0, 20, 20), new RadialGradient(10, 10, 6, [[0.0, $red], [1.0, Color::rgba(255, 0, 0, 0.0)]]));
    });
    [, $paint] = $e->argsOf('fill')[0];

    expect([$paint->cx, $paint->cy, $paint->radius])->toBe([20.0, 20.0, 12.0])
        ->and($paint->stops[0][1]->alpha)->toBe(0.5)
        ->and($paint->stops[1][1]->alpha)->toBe(0.0);
});

it('paints nothing for a rect with no area or one off the viewport', function () {
    $e = new SpyEngine;
    $stops = [[0.0, Color::rgb(0, 0, 0)], [1.0, Color::rgb(255, 255, 255)]];

    $e->frame(function () use ($e, $stops) {
        $p = new EnginePainter($e, 1.0);
        $p->gradient(new Rect(0, 0, 0, 10), new LinearGradient(0, 0, 10, 0, $stops));
        $p->gradient(new Rect(5000, 5000, 10, 10), new LinearGradient(5000, 0, 5010, 0, $stops));
    });

    expect($e->argsOf('fill'))->toBe([]);
});
