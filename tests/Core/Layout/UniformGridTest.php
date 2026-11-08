<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\UniformGrid;

it('gives every cell the largest child size and flows in columns', function () {
    $tree = new Tree($g = new UniformGrid('window', 2, 2));
    foreach ([[10, 5], [30, 8], [20, 20]] as $i => [$w, $h]) {
        $g->add(new Spacer("s$i", $w, $h));
    }

    expect($g->measure(new Size(500, 500)))->toEqual(new Size(62, 42));
    $tree->layout(new Size(102, 42));
    expect($g->children()[2]->bounds())->toEqual(new Rect(0, 22, 50, 20));
});

it('places by at() without flow columns', function () {
    $tree = new Tree($g = new UniformGrid('window'));
    $g->at(1, 1)->add($a = new Spacer('a', 10, 10));
    $tree->layout(new Size(40, 40));

    expect($a->bounds())->toEqual(new Rect(20, 20, 20, 20));
});
