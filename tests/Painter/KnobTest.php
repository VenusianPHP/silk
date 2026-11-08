<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Painter\Knob;
use Venusian\Silk\Tests\Support\SpyPainter;

/* Knob: Raven's paint_knob (common.c:209-226). */

function knobPaint(float $press, bool $enabled = true): SpyPainter
{
    $look = BuiltInThemes::register(new LookManager, 'sans-serif', 'monospace')->use('raven-glass-dark')->current();
    $p = new SpyPainter;
    Knob::paint($p, $look, new Rect(10, 10, 30, 20), $press, $enabled);

    return $p;
}

it('is a white capsule with a soft shadow at rest', function () {
    $p = knobPaint(0.0);
    [$shadow] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'shadow'));
    [$rect] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($p->methods())->toBe(['shadow', 'rect'])
        ->and($shadow[1][2])->toBe(5.0)
        ->and($rect[1][2])->toBe(10.0)
        ->and($rect[1][1]->toHex())->toBe('#ffffff');
});

it('grows into a lens of clear glass when held, the solid knob fading into it', function () {
    $p = knobPaint(1.0);
    [$glass] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'glass'));
    [$opacity] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'pushOpacity'));

    // grow 3: the lens is 2.8 × 3 wider and 2 × 3 taller, each side by 4.2 and 3, its radius 10 + 3
    expect($glass[1][0]->x)->toEqualWithDelta(10 - 4.2, 1e-9)
        ->and($glass[1][0]->w)->toEqualWithDelta(30 + 8.4, 1e-9)
        ->and($glass[1][0]->h)->toEqualWithDelta(26.0, 1e-9)
        ->and($glass[1][1])->toBe(13.0)
        ->and($glass[1][2]->frost)->toBe(0.0)
        ->and($opacity[1][0])->toBe(0.0);
});

it('stays a solid knob when disabled, in a dimmer grey', function () {
    $p = knobPaint(1.0, false);
    [$rect] = array_values(array_filter($p->calls, fn ($c) => $c[0] === 'rect'));

    expect($p->methods())->not->toContain('glass')
        ->and($rect[1][1]->red)->toBe(0.85);
});
