<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\EnginePainter;
use Surface\Contracts\Rasterize\FillRule;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyEngine;

function painted(callable $draw, float $scale = 1.0): SpyEngine
{
    $e = new SpyEngine;
    $e->frame(fn () => $draw(new EnginePainter($e, $scale)));

    return $e;
}

it('lowers a rect with radii to one roundRect on snapped edges, no fillPath', function () {
    $e = painted(fn ($p) => $p->rect(new Rect(1.2, 1.2, 10, 10), Color::hex('#ff0000'), 4.0), 2.0);

    expect($e->methods())->toBe(['roundRect'])
        ->and($e->argsOf('fillPath'))->toBe([])
        ->and(array_slice($e->argsOf('roundRect')[0], 0, 5))->toEqual([2.0, 2.0, 20.0, 20.0, [8.0, 8.0, 8.0, 8.0]])
        ->and($e->argsOf('roundRect')[0][5]->toHex())->toBe('#ff0000');
});

it('hands a 0.3-unit border to the engine as 1 px at scale 1', function () {
    $e = painted(fn ($p) => $p->rect(new Rect(10, 10, 40, 20), Color::hex('#111111'), 0.0, Color::hex('#eeeeee'), 0.3));

    [$args] = $e->argsOf('roundRect');
    expect($args[6]->toHex())->toBe('#eeeeee')->and($args[7])->toBe(1.0);
});

it('clamps radii to half the short side', function () {
    $e = painted(fn ($p) => $p->rect(new Rect(0, 0, 20, 10), Color::hex('#000000'), [50, 50, 50, 50]));

    expect($e->argsOf('roundRect')[0][4])->toEqual([5.0, 5.0, 5.0, 5.0]);
});

it('multiplies opacity into the fill alpha, nesting', function () {
    $e = painted(fn ($p) => $p->pushOpacity(0.5)->pushOpacity(0.5)
        ->rect(new Rect(0, 0, 10, 10), new Color(1, 0, 0, 0.8))
        ->popOpacity()
        ->rect(new Rect(0, 0, 10, 10), new Color(1, 0, 0, 0.8)));

    [$a, $b] = $e->argsOf('roundRect');
    expect($a[5]->alpha)->toEqualWithDelta(0.2, 1e-9)
        ->and($b[5]->alpha)->toEqualWithDelta(0.4, 1e-9);
});

it('culls a rect more than 48 units outside the viewport', function () {
    $e = painted(fn ($p) => $p->rect(new Rect(-200, 10, 100, 10), Color::hex('#000000'))
        ->rect(new Rect(10, 400, 10, 10), Color::hex('#000000'))
        ->shadow(new Rect(-300, 0, 10, 10), 4, 8, Color::hex('#000000')));

    expect($e->calls)->toBe([]);

    $e = painted(fn ($p) => $p->rect(new Rect(-40, 10, 100, 10), Color::hex('#000000')));
    expect($e->methods())->toBe(['roundRect']);
});

/** The drawing calls of a frame, without the clips the painter set. */
function drawn(SpyEngine $e): array
{
    return array_values(array_diff($e->methods(), ['clip']));
}

it('culls what lies wholly outside the clip, and keeps what touches it', function () {
    $e = painted(fn ($p) => $p->pushClip(new Rect(100, 100, 50, 50))
        ->rect(new Rect(10, 10, 40, 40), Color::hex('#000000'))
        ->rect(new Rect(150, 100, 40, 40), Color::hex('#000000'))
        ->shadow(new Rect(10, 10, 20, 20), 4, 8, Color::hex('#000000'))
        ->line(0, 0, 90, 90, Color::hex('#000000'), 2.0)
        ->popClip());
    expect(drawn($e))->toBe([]);

    $e = painted(fn ($p) => $p->pushClip(new Rect(100, 100, 50, 50))
        ->rect(new Rect(140, 140, 40, 40), Color::hex('#000000'))
        ->shadow(new Rect(60, 100, 20, 20), 4, 24, Color::hex('#000000'))
        ->popClip());
    expect(drawn($e))->toBe(['roundRect', 'shadow']);
});

it('culls a path by the box of its points, grown by half a stroke and the miter limit', function () {
    $path = (new \Surface\NutsAndBolts\Path())->moveTo(10, 10)->cubicTo(20, 0, 30, 0, 40, 10);
    $style = new \Surface\Contracts\Drawing\StrokeStyle(4.0);

    $e = painted(fn ($p) => $p->pushClip(new Rect(100, 100, 50, 50))->fill($path, Color::hex('#000000'))->stroke($path, Color::hex('#000000'), $style)->popClip());
    expect(drawn($e))->toBe([]);

    // a clip 3 units from the box: a stroke of 4 with the default miter limit reaches 2 x limit past it, a fill does not
    $near = new Rect(43, 0, 20, 20);
    $e = painted(fn ($p) => $p->pushClip($near)->fill($path, Color::hex('#000000'))->popClip());
    expect(drawn($e))->toBe([]);
    $e = painted(fn ($p) => $p->pushClip($near)->stroke($path, Color::hex('#000000'), $style)->popClip());
    expect(drawn($e))->toBe(['stroke']);
});

it('lowers a shadow to one engine shadow op, unoffset (the engine offsets by blur/4)', function () {
    $e = painted(fn ($p) => $p->shadow(new Rect(20, 20, 40, 20), 4, 8, new Color(0, 0, 0, 0.8), 0, 6));

    [$args] = $e->argsOf('shadow');
    expect($e->methods())->toBe(['shadow'])
        ->and(array_slice($args, 0, 6))->toEqual([20.0, 26.0, 40.0, 20.0, 4.0, 8.0])
        ->and($args[6]->alpha)->toEqualWithDelta(0.8, 1e-9);
});

it('lowers a line to one round-capped contour, unsnapped', function () {
    $e = painted(fn ($p) => $p->line(10.25, 10, 30.25, 10, Color::hex('#00ff00'), 2.0));

    [[$contours]] = $e->argsOf('fillPath');
    [$minX, $minY, $maxX, $maxY] = bbox($contours[0]);
    expect($contours)->toHaveCount(1)
        ->and($minX)->toEqualWithDelta(9.25, 1e-9)
        ->and($maxX)->toEqualWithDelta(31.25, 1e-9)
        ->and($minY)->toEqualWithDelta(9.0, 1e-9)
        ->and($maxY)->toEqualWithDelta(11.0, 1e-9);
});

it('scales and translates a pushed transform before snapping', function () {
    $e = painted(fn ($p) => $p->pushTransform(2.0, 5, 5)
        ->rect(new Rect(0, 0, 10, 10), Color::hex('#000000'))
        ->popTransform()
        ->triangle(0, 0, 10, 0, 0, 10, Color::hex('#000000')));

    expect(array_slice($e->argsOf('roundRect')[0], 0, 4))->toEqual([5.0, 5.0, 20.0, 20.0])
        ->and($e->argsOf('fillTriangle')[0])->toEqual([0.0, 0.0, 10.0, 0.0, 0.0, 10.0, Color::hex('#000000')]);
});

it('draws nothing for an empty rect or an empty clip', function () {
    $e = painted(fn ($p) => $p->rect(new Rect(0, 0, 0, 10), Color::hex('#000000'))
        ->pushClip(new Rect(0, 0, 0, 0))
        ->rect(new Rect(0, 0, 10, 10), Color::hex('#000000'))
        ->popClip());

    expect($e->methods())->toBe(['clip', 'clip']);
});

it('reports its scale and logical viewport', function () {
    $e = new SpyEngine(400, 300);
    $p = new EnginePainter($e, 2.0);

    expect($p->scale())->toBe(2.0)->and($p->viewport())->toEqual(new Rect(0, 0, 200, 150));
});

it('keeps a line width fractional, max(1, thickness x scale), as Raven rvn_paint_line (draw.c:612)', function (float $thickness, float $scale, float $device) {
    $e = painted(fn ($p) => $p->line(10, 10, 30, 10, Color::hex('#00ff00'), $thickness), $scale);

    [[$contours]] = $e->argsOf('fillPath');
    [, $minY, , $maxY] = bbox($contours[0]);
    expect($maxY - $minY)->toEqualWithDelta($device, 1e-9);
})->with([
    'chevron 1.25 at 2x' => [1.25, 2.0, 2.5],
    'glyph 1.5 at 1x' => [1.5, 1.0, 1.5],
    'hairline 0.5 at 1x is 1' => [0.5, 1.0, 1.0],
]);
