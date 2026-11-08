<?php

use Silk\Contracts\Geometry\Rect;
use Silk\Contracts\Geometry\Size;
use Silk\Contracts\Widget\Align;
use Silk\Contracts\Widget\Slot;
use Silk\Core\Layout\Spacer;
use Silk\Core\Tree\Tree;
use Silk\Core\Layout\WrapBox;

it('flows left to right and wraps when the width is used up', function () {
    $tree = new Tree($w = new WrapBox('window', 5));
    foreach (range(1, 4) as $i) {
        $w->add(new Spacer("s$i", 30, 10 + $i));
    }

    expect($w->measure(new Size(100, 500)))->toEqual(new Size(100, 32))
        ->and($w->measure(new Size(1000, 500)))->toEqual(new Size(135, 14));
    $tree->layout(new Size(100, 100));
    expect($w->children()[3]->bounds())->toEqual(new Rect(0, 18, 30, 14));
});
