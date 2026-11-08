<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Look\Role;
use Silk\Looks\BuiltInThemes;
use Silk\Looks\LookManager;
use Silk\Widgets\ScrollBars;
use Venusian\Silk\Tests\Support\SpyPainter;

it('sizes the thumb as Raven: max(track x viewport / content, min(24, track)), placed by the offset (common.c:553-558)', function () {
    expect(ScrollBars::thumb(100, 50, 200, 75))->toBe([37.5, 25.0])
        ->and(ScrollBars::thumb(100, 10, 1000, 0))->toBe([0.0, 24.0])
        ->and(ScrollBars::thumb(100, 100, 50, 0))->toBe([0.0, 100.0]);
});

it('draws the Raven bar: the Track at 0.6, the thumb inset 2, rounded (common.c:638-641)', function () {
    $look = barLook('raven-dark');
    $calls = barCalls('raven-dark');
    $track = $look->color(Role::Track);

    expect($calls)->toHaveCount(2)
        ->and([$calls[0][1][0], $calls[0][1][1]->alpha])->toEqual([new Rect(90, 0, 10, 100), $track->alpha * 0.6])
        ->and([$calls[1][1][0], $calls[1][1][1]->toHex(), $calls[1][1][2]])->toEqual([new Rect(92, 22, 6, 36), $look->color(Role::Handle)->toHex(), 3.0])
        ->and(barCalls('raven-dark', true)[1][1][1]->toHex())->toBe($look->color(Role::HandleHovered)->toHex());
});

it('draws nothing of an overlay bar that has faded (common.c:597-603)', function () {
    expect(barCalls('macos-light', visible: 0.01))->toBe([]);
});

it('draws the macOS overlay thumb 7 wide at rest by the edge, 9 wide and centred over a Popup back when wide (common.c:593-627)', function () {
    $rest = barCalls('macos-dark');
    $wide = barCalls('macos-dark', true, 1.0, 1.0);
    $thumbRest = array_values(array_filter($rest, fn ($c) => $c[0] === 'rect'))[0][1][0];
    $rects = array_values(array_filter($wide, fn ($c) => $c[0] === 'rect'));

    expect([$thumbRest->x, $thumbRest->w])->toBe([90 + 10 - 3 - 7.0, 7.0])
        ->and($rects)->toHaveCount(2)
        ->and($rects[0][1][1]->toHex())->toStartWith(substr(barLook('macos-dark')->color(Role::Popup)->toHex(), 0, 7))
        ->and([$rects[1][1][0]->x, $rects[1][1][0]->w])->toBe([90 + 10 - 3 - 9 - (5 - 3 - 4.5), 9.0]);
});

it('draws the GNOME overlay thumb 3 wide at rest, 8 when wide, over a Window back (common.c:593-600)', function () {
    $rest = array_values(array_filter(barCalls('gnome-light'), fn ($c) => $c[0] === 'rect'));
    $wide = array_values(array_filter(barCalls('gnome-light', true, 1.0, 1.0), fn ($c) => $c[0] === 'rect'));

    expect($rest[0][1][0]->w)->toBe(3.0)
        ->and($wide[1][1][0]->w)->toBe(8.0)
        ->and(substr($wide[0][1][1]->toHex(), 0, 7))->toBe(substr(barLook('gnome-light')->color(Role::Window)->toHex(), 0, 7));
});

it('squeezes the drawn thumb against the end the content is pulled past, to 12 at least (common.c:568-575)', function () {
    expect(ScrollBars::drawn(100, 50, 200, 75))->toBe([37.5, 25.0])
        ->and(ScrollBars::drawn(100, 50, 200, -10))->toBe([0.0, 15.0])
        ->and(ScrollBars::drawn(100, 50, 200, -40))->toBe([0.0, 12.0])
        ->and(ScrollBars::drawn(100, 50, 200, 160))->toBe([85.0, 15.0]);
});
