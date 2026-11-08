<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Drawing\Effects\Blur;
use Surface\Contracts\Drawing\Effects\FilterChain;
use Surface\Contracts\Drawing\StrokeStyle;
use Surface\Contracts\Framebuffers\FormatSpec;
use Surface\Drawing\Velvet\VelvetGE;
use Surface\Framebuffers\Native\NativeFullFramebuffer;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\Path;

/** Paints on a 60 x 40 black Velvet frame at device scale 2; returns a reader of a device pixel's [r, g, b]. */
function velvetPaint(Closure $paint): Closure
{
    $v = new VelvetGE(new NativeFullFramebuffer(FormatSpec::rgba8(), 60, 40), new Surface\Rasterize\Native\NativeRasterizeDriver, Surface\Contracts\Rasterize\Edges::HARD);
    $v->frame(function () use ($v, $paint) {
        $v->clear(Color::rgb(0, 0, 0));
        $paint(new EnginePainter($v, 2.0));
    });
    $px = $v->framebuffer()->toRgba8();

    return fn (int $x, int $y) => array_map(fn ($i) => ord($px[($y * 60 + $x) * 4 + $i]), [0, 1, 2]);
}

it('strokes a path through the transform, its width in logical units', function () {
    $at = velvetPaint(fn (EnginePainter $p) => $p->pushTransform(1.0, 5, 0)
        ->stroke((new Path)->moveTo(0, 10)->lineTo(20, 10), Color::rgb(255, 0, 0), new StrokeStyle(2.0)));

    // device: x 10..50 at y 20, 4 wide
    expect($at(30, 19))->toBe([255, 0, 0])->and($at(30, 23))->toBe([0, 0, 0])->and($at(6, 20))->toBe([0, 0, 0]);
});

it('fills a path faded by the opacity pushed', function () {
    $at = velvetPaint(fn (EnginePainter $p) => $p->pushOpacity(0.5)
        ->fill((new Path)->moveTo(2, 2)->lineTo(22, 2)->lineTo(22, 12)->lineTo(2, 12)->close(), Color::rgb(255, 255, 255)));

    expect($at(20, 10)[0])->toBeGreaterThan(120)->toBeLessThan(135)->and($at(50, 30))->toBe([0, 0, 0]);
});

it('lays a layer down once at its opacity and the one pushed, its children not faded twice', function () {
    $at = velvetPaint(fn (EnginePainter $p) => $p->pushOpacity(0.5)
        ->layer(new Rect(0, 0, 30, 20), fn ($q) => $q->rect(new Rect(2, 2, 20, 10), Color::rgb(255, 255, 255)), 0.5));

    expect($at(20, 10)[0])->toBeGreaterThan(58)->toBeLessThan(70);
});

it('runs a layer\'s filter over its group: a blurred stroke reaches past its width', function () {
    $sharp = velvetPaint(fn (EnginePainter $p) => $p->layer(new Rect(0, 0, 30, 20), fn ($q) => $q->stroke((new Path)->moveTo(2, 10)->lineTo(28, 10), Color::rgb(255, 255, 255), new StrokeStyle(1.0))));
    $soft = velvetPaint(fn (EnginePainter $p) => $p->layer(new Rect(0, 0, 30, 20), fn ($q) => $q->stroke((new Path)->moveTo(2, 10)->lineTo(28, 10), Color::rgb(255, 255, 255), new StrokeStyle(1.0)),
        filter: FilterChain::of(Blur::gaussian(3.0))));

    expect($sharp(30, 24)[0])->toBe(0)->and($soft(30, 24)[0])->toBeGreaterThan(0)->and($soft(30, 20)[0])->toBeLessThan(255);
});
