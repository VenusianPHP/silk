<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Core\Layout\Column;
use Silk\Core\Layout\Row;
use Silk\Core\Layout\Spacer;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Tree\Tree;

it('stacks a column with spacing 6 and padding 10', function () {
    $tree = new Tree($col = new Column('window', 6, 10));
    $col->add($a = new Spacer('a', 50, 20));
    $col->add($b = new Spacer('b', 30, 26));

    expect($col->measure(new Size(500, 500)))->toEqual(new Size(70, 72));

    $tree->layout(new Size(200, 300));

    // children fill the cross axis by default
    expect($a->bounds())->toEqual(new Rect(10, 10, 180, 20))
        ->and($b->bounds())->toEqual(new Rect(10, 36, 180, 26));
});

it('places a row with cross-axis alignment from the slot', function () {
    $tree = new Tree($row = new Row('window', 4, 2));
    $row->add($a = new Spacer('a', 20, 10), new Slot(align: Align::Center));
    $row->add($b = new Spacer('b', 30, 10), new Slot(align: Align::End));
    $row->add($c = new Spacer('c', 30, 10), new Slot(align: Align::Start));

    $tree->layout(new Size(200, 50));

    expect($a->bounds())->toEqual(new Rect(2, 20, 20, 10))
        ->and($b->bounds())->toEqual(new Rect(26, 38, 30, 10))
        ->and($c->bounds())->toEqual(new Rect(60, 2, 30, 10));
});

it('shares extra main-axis space by grow', function () {
    $tree = new Tree($row = new Row('window'));
    $row->add($a = new Spacer('a', 10, 10));
    $row->add($b = new Spacer('b', 10, 10), new Slot(grow: 1));
    $row->add($c = new Spacer('c', 10, 10), new Slot(grow: 3));

    $tree->layout(new Size(110, 10));

    expect($a->bounds()->w)->toBe(10.0)
        ->and($b->bounds()->w)->toBe(30.0)
        ->and($c->bounds())->toEqual(new Rect(40, 0, 70, 10));
});

it('leaves hidden children out of the layout', function () {
    $tree = new Tree($col = new Column('window', 6));
    $col->add($a = new Spacer('a', 10, 10));
    $col->add($b = (new Spacer('b', 10, 10))->setVisible(false));
    $col->add($c = new Spacer('c', 10, 10));

    $tree->layout(new Size(10, 100));

    expect($c->bounds()->y)->toBe(16.0);
});

it('removes a child and re-lays out', function () {
    $tree = new Tree($col = new Column('window'));
    $col->add($a = new Spacer('a', 10, 10));
    $col->add($b = new Spacer('b', 10, 10));
    $tree->layout(new Size(10, 100));

    $col->remove($a);

    expect($col->children())->toBe([$b])
        ->and($tree->layout(new Size(10, 100)))->toBe(1)
        ->and($b->bounds()->y)->toBe(0.0);
});
