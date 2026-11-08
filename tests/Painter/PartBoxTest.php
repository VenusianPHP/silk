<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Painter\PartBox;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

function boxCalls(?Color $top, ?Color $bottom, float $width = 1.0): array
{
    $p = new SpyPainter;
    PartBox::paint($p, new Rect(10, 20, 100, 26), Color::hex('#ffffff'), 4.0, Color::hex('#00000026'), $width, $top, $bottom);

    return array_map(fn ($c) => [$c[0], array_map(fn ($a) => $a instanceof Color ? $a->toHex() : ($a instanceof Rect ? [$a->x, $a->y, $a->w, $a->h] : $a), $c[1])], $p->calls);
}

it('is one rect when no edge has its own colour, as before', function () {
    expect(boxCalls(null, null))->toBe([['rect', [[10.0, 20.0, 100.0, 26.0], '#ffffff', 4.0, '#00000026', 1.0]]]);
});

it('draws the fill, then the outline in three clipped bands, as Raven rvn_paint_part (look.c:379-404)', function () {
    $calls = boxCalls(Color::hex('#ffffff26'), Color::hex('#00000040'));

    expect($calls)->toBe([
        ['rect', [[10.0, 20.0, 100.0, 26.0], '#ffffff', 4.0]],
        // top band: width 1 + 0.5, from one above the box
        ['pushClip', [[9.0, 19.0, 102.0, 2.5]]],
        ['rect', [[10.0, 20.0, 100.0, 26.0], '#00000000', 4.0, '#ffffff26', 1.0]],
        ['popClip', []],
        ['pushClip', [[9.0, 21.5, 102.0, 23.0]]],
        ['rect', [[10.0, 20.0, 100.0, 26.0], '#00000000', 4.0, '#00000026', 1.0]],
        ['popClip', []],
        ['pushClip', [[9.0, 44.5, 102.0, 2.5]]],
        ['rect', [[10.0, 20.0, 100.0, 26.0], '#00000000', 4.0, '#00000040', 1.0]],
        ['popClip', []],
    ]);
});

it('gives the bottom band the border colour when only the top has its own (look.c:388)', function () {
    $calls = boxCalls(Color::hex('#ffffff26'), null);

    expect($calls[4])->toBe(['pushClip', [[9.0, 21.5, 102.0, 24.5]]])
        ->and($calls[7])->toBe(['pushClip', [[9.0, 46.0, 102.0, 1.0]]])
        ->and($calls[8][1][3])->toBe('#00000026');
});
