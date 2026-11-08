<?php

use Silk\Contracts\Geometry\Insets;
use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Painter\Painter;
use Surface\NutsAndBolts\Color;
use Venusian\Silk\Tests\Support\SpyPainter;

it('returns null for disjoint rects', function () {
    expect((new Rect(0, 0, 10, 10))->intersect(new Rect(20, 20, 5, 5)))->toBeNull()
        // touching edges share no area
        ->and((new Rect(0, 0, 10, 10))->intersect(new Rect(10, 0, 5, 5)))->toBeNull();
});

it('returns the overlap of intersecting rects', function () {
    expect((new Rect(0, 0, 10, 10))->intersect(new Rect(5, 2, 10, 4)))->toEqual(new Rect(5, 2, 5, 4));
});

it('insets without going negative', function () {
    expect((new Rect(0, 0, 20, 10))->inset(new Insets(1, 2, 3, 4)))->toEqual(new Rect(1, 2, 16, 4))
        ->and((new Rect(0, 0, 4, 4))->inset(new Insets(5, 5, 5, 5)))->toEqual(new Rect(5, 5, 0, 0));
});

it('contains points half-open', function () {
    $r = new Rect(0, 0, 10, 10);

    expect($r->contains(0, 0))->toBeTrue()
        ->and($r->contains(9.99, 9.99))->toBeTrue()
        ->and($r->contains(10, 5))->toBeFalse();
});

it('lets a painter chain and records calls in order', function () {
    $p = new SpyPainter(2.0);
    $c = new Color(1, 0, 0);

    $out = $p->pushClip(new Rect(0, 0, 10, 10))
        ->rect(new Rect(1, 1, 5, 5), $c, [1, 2, 3, 4], $c, 1.0)
        ->line(0, 0, 5, 5, $c)
        ->popClip();

    expect($out)->toBe($p)->toBeInstanceOf(Painter::class)
        ->and($p->methods())->toBe(['pushClip', 'rect', 'line', 'popClip'])
        ->and($p->scale())->toBe(2.0);
});
