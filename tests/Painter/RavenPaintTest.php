<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\MarkShape;
use Silk\Painter\RavenPaint;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\LinearGradient;
use Venusian\Silk\Tests\Support\SpyPainter;

/*
 * Raven's canvas_look.c:155-200 (rvn_paint_gradient), as one linear gradient over the rect.
 */

it('paints a gradient as one linear gradient over the rect, left to right or top to bottom', function () {
    $across = new SpyPainter;
    RavenPaint::gradient($across, new Rect(10, 20, 30, 40), null, Color::rgb(0, 0, 0), Color::rgb(255, 255, 255), true);
    $down = new SpyPainter;
    RavenPaint::gradient($down, new Rect(10, 20, 30, 40), null, Color::rgb(0, 0, 0), Color::rgb(255, 255, 255), false);

    expect($across->calls)->toHaveCount(1)
        ->and($across->calls[0][0])->toBe('gradient')
        ->and($across->calls[0][1][0])->toEqual(new Rect(10, 20, 30, 40))
        ->and($across->calls[0][1][1])->toEqual(new LinearGradient(10, 20, 40, 20, [[0.0, Color::rgb(0, 0, 0)], [1.0, Color::rgb(255, 255, 255)]]))
        ->and($down->calls[0][1][1])->toEqual(new LinearGradient(10, 20, 10, 60, [[0.0, Color::rgb(0, 0, 0)], [1.0, Color::rgb(255, 255, 255)]]));
});

it('gives the gradient the corners to round', function () {
    $p = new SpyPainter;
    RavenPaint::gradient($p, new Rect(0, 0, 50, 20), [8.0, 8.0, 0.0, 0.0], Color::rgb(0, 0, 0), Color::rgb(255, 0, 0), false);
    $plain = new SpyPainter;
    RavenPaint::gradient($plain, new Rect(0, 0, 50, 20), null, Color::rgb(0, 0, 0), Color::rgb(255, 0, 0), false);

    expect($p->calls[0][1][2])->toBe([8.0, 8.0, 0.0, 0.0])
        ->and($plain->calls[0][1][2])->toBe(0.0);
});

it('paints one box for equal colours, and nothing for a rect with no area', function () {
    $same = new SpyPainter;
    RavenPaint::gradient($same, new Rect(0, 0, 10, 10), [2.0, 2.0, 2.0, 2.0], Color::rgb(9, 9, 9), Color::rgb(9, 9, 9), true);
    $none = new SpyPainter;
    RavenPaint::gradient($none, new Rect(0, 0, 0, 10), null, Color::rgb(0, 0, 0), Color::rgb(9, 9, 9), true);

    expect($same->calls)->toHaveCount(1)->and($same->calls[0][0])->toBe('rect')->and($same->calls[0][1][2])->toBe([2.0, 2.0, 2.0, 2.0])
        ->and($none->calls)->toBe([]);
});

it('draws marks as Raven: a diamond of two triangles, a ring as a hollow circle, a glow as a shadow', function () {
    $p = new SpyPainter;
    RavenPaint::mark($p, MarkShape::Diamond, 10, 10, 4, Color::rgb(1, 2, 3));
    RavenPaint::mark($p, MarkShape::Ring, 10, 10, 5, Color::rgb(1, 2, 3), ring: 2.0, inside: Color::rgb(9, 9, 9));
    RavenPaint::markGlow($p, MarkShape::Circle, 10, 10, 5, Color::rgb(1, 2, 3), 1.0);

    expect(array_column($p->calls, 0))->toBe(['triangle', 'triangle', 'rect', 'rect', 'shadow'])
        // the diamond reaches half x 1.25 = 5 from its centre
        ->and(array_slice($p->calls[0][1], 0, 6))->toBe([5.0, 10.0, 10.0, 5.0, 15.0, 10.0])
        // the ring's hole: 5 - clamp(2, 1, 3) = 3
        ->and($p->calls[3][1][0]->w)->toBe(6.0)
        // glow: blur 5 x 1.6 + 6 = 14, alpha 0.65
        ->and($p->calls[4][1][2])->toBe(14.0)->and($p->calls[4][1][3]->alpha)->toBe(0.65);
});
