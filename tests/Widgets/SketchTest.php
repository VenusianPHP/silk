<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Silk\Widgets\Sketch;
use Surface\Contracts\Drawing\RenderingEngine;
use Surface\Contracts\Framebuffers\Region;
use Venusian\Silk\Tests\Support\SpyEngine;
use Venusian\Silk\Tests\Support\SpyPainter;

it('calls the sketch with the engine, its device rect and the scale', function () {
    $got = null;
    $sketch = new Sketch('view', function (RenderingEngine $engine, Rect $device, float $scale) use (&$got) {
        $got = [$device, $scale, $engine->clipRegion()];
    });
    $sketch->arrange(new Rect(10, 10, 50, 20));
    $e = new SpyEngine;
    $e->frame(fn () => $sketch->paint(new EnginePainter($e, 2.0)));

    expect($got)->toEqual([new Rect(20, 20, 100, 40), 2.0, new Region(20, 20, 100, 40)]);
});

it('does not leak the engine clip or transform to its siblings', function () {
    $sketch = new Sketch('view', function (RenderingEngine $engine) {
        $engine->clip(new Region(0, 0, 1, 1));
        $engine->translate(500, 500);
    });
    $sketch->arrange(new Rect(10, 10, 50, 20));
    $e = new SpyEngine;
    $after = null;
    $e->frame(function () use ($e, $sketch, &$after) {
        $sketch->paint(new EnginePainter($e, 1.0));
        $after = [$e->clipRegion(), $e->matrix()->apply(0, 0)];
    });

    expect($after)->toEqual([new Region(0, 0, 400, 300), [0.0, 0.0]]);
});

it('repaints on invalidate, and paints through the painter', function () {
    $sketch = new Sketch('view', fn () => null);
    $sketch->markPainted();
    $sketch->invalidate();
    $p = new SpyPainter;
    $sketch->arrange(new Rect(0, 0, 10, 10));
    $sketch->paint($p);

    expect($sketch->needsPaint())->toBeTrue()->and($p->methods())->toBe(['sketch']);
});
