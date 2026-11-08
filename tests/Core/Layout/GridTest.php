<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\Grid;

it('sizes auto tracks to their content and places cells with spacing', function () {
    $tree = new Tree($g = new Grid('window', 4));
    $g->at(0, 0)->add($a = new Spacer('a', 30, 10));
    $g->at(0, 1)->add($b = new Spacer('b', 50, 20));
    $g->at(1, 0)->add($c = new Spacer('c', 40, 15));

    expect($g->measure(new Size(500, 500)))->toEqual(new Size(94, 39));
    $tree->layout(new Size(94, 39));
    expect($a->bounds())->toEqual(new Rect(0, 0, 40, 20))
        ->and($b->bounds())->toEqual(new Rect(44, 0, 50, 20))
        ->and($c->bounds())->toEqual(new Rect(0, 24, 40, 15));
});

it('grows the last spanned track for a spanning cell', function () {
    $g = new Grid('window', 4);
    $g->at(0, 0)->add(new Spacer('a', 20, 10));
    $g->at(0, 1)->add(new Spacer('b', 20, 10));
    $g->at(1, 0, 1, 2)->add(new Spacer('wide', 100, 10));

    expect($g->measure(new Size(500, 500))->w)->toBe(100.0);
});

it('shares leftover space by star fill and keeps fixed tracks', function () {
    $tree = new Tree($g = new Grid('window'));
    $g->at(0, 0)->add($a = new Spacer('a', 20, 10));
    $g->at(0, 1)->add($b = new Spacer('b', 20, 10));
    $g->at(0, 2)->add($c = new Spacer('c', 20, 10));
    $g->setColumnFill(1, 1)->setColumnFill(2, 3)->setColumnWidth(0, 50);
    $tree->layout(new Size(250, 10));

    expect($a->bounds()->w)->toBe(50.0)->and($b->bounds()->w)->toBe(60.0)->and($c->bounds()->w)->toBe(140.0);
});

it('refuses a negative cell or a span below 1', function () {
    (new Grid('g'))->at(0, -1);
})->throws(InvalidArgumentException::class);
