<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\Anchored;

it('places at() frames as TKFixed and moves and resizes them', function () {
    $tree = new Tree($a = new Anchored('window'));
    $a->at(10, 20, 30, 40)->add($s = new Spacer('s', 5, 5));
    $tree->layout(new Size(200, 200));
    expect($a->frameOf($s))->toEqual(new Rect(10, 20, 30, 40));

    $a->move($s, 50, 60)->resize($s, 10, 10);
    $tree->layout(new Size(200, 200));
    expect($a->frameOf($s))->toEqual(new Rect(50, 60, 10, 10))->and($a->measure(new Size(9, 9)))->toEqual(new Size(0, 0));
});

it('anchors with a pivot, and stretches between different anchors', function () {
    $tree = new Tree($a = new Anchored('window'));
    $a->anchor([0.5, 0.5, 0.5, 0.5], pivot: [0.5, 0.5])->add($centred = new Spacer('c', 20, 10));
    $a->anchor([0, 1, 1, 1], 10, -30, 10, 30)->add($bar = new Spacer('bar', 1, 1));
    $tree->layout(new Size(200, 100));

    expect($centred->bounds())->toEqual(new Rect(90, 45, 20, 10))
        ->and($bar->bounds())->toEqual(new Rect(10, 70, 180, 30));
});

it('refuses a child that is not its own', function () {
    (new Anchored('a'))->move(new Spacer('s', 1, 1), 0, 0);
})->throws(InvalidArgumentException::class);
